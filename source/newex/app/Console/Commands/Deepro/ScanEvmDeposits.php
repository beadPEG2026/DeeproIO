<?php

namespace App\Console\Commands\Deepro;

use App\Models\Deposit\DepositChannel;
use App\Models\Wallet\WalletAddress;
use App\Services\Deposit\{DepositChannelPolicy, EvmDepositClient, EvmExplorerClient, EvmLogDiscovery, VerifiedChainDeposit, ChainAmount};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};
use RuntimeException;

final class ScanEvmDeposits extends Command
{
    protected $signature = 'deepro:scan-evm-deposits {chain : ethereum, bsc, polygon or xlayer} {--lane=live : live or backfill}';
    protected $description = 'One scanner per chain, grouped discovery, bounded live/backfill and atomic credit';

    public function handle(): int
    {
        $chain = (string) $this->argument('chain');
        $lane = (string) $this->option('lane');
        if (!in_array($chain, array_keys(config('deposits.evm', [])), true) || !in_array($lane, ['live', 'backfill'], true)) return self::FAILURE;
        $key = 'deposit-scanner:' . $chain;
        if (!DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired', [$key])->acquired) return self::SUCCESS;
        try {
            $channels = DepositChannel::where('chain', $chain)->whereIn('state', ['active', 'pilot', 'tested'])->orderBy('id')->get()->all();
            return $this->runChannels($channels, $lane) ? self::SUCCESS : self::FAILURE;
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }
    }

    /** Single-channel entry retained for existing acceptance callers. */
    public function check(DepositChannel $channel): void
    {
        $this->runChannels([$channel], 'all', true);
    }

    public function runChannels(array $channels, string $lane = 'live', bool $throw = false): bool
    {
        if (!$channels) return true;
        $chain = $channels[0]->chain;
        foreach ($channels as $c) if ($c->chain !== $chain) throw new RuntimeException('DEPOSIT_WRONG_CHAIN');
        if ($lane === 'backfill') {
            // Live scanning establishes persisted gaps. An idle historical timer
            // must not fetch a slow head and hold the chain lock into the next minute.
            $pending = DB::table('chain_deposit_scan_states')->where('chain', $chain)
                ->whereIn('scope', array_map(fn($c) => $c->scanScope(), $channels))
                ->whereNotNull('realtime_from')->pluck('scope')->all();
            $channels = array_values(array_filter($channels, fn($c) => in_array($c->scanScope(), $pending, true)));
            if (!$channels) return true;
        }
        $client = app(EvmDepositClient::class);
        $explorer = app(EvmExplorerClient::class);
        $indexedBackfill = $lane === 'backfill' && $explorer->available($chain) && config('deposits.explorer.recipient_filter');
        $discovery = new EvmLogDiscovery($client, $explorer, $indexedBackfill);
        $stopAt = microtime(true) + ($lane === 'backfill' ? 20 : 45);
        $client->beginRound((int) config('deposits.scan.' . ($lane === 'backfill' ? 'backfill_requests' : 'live_requests')), $stopAt);
        $explorer->setDeadline($stopAt);
        $ok = true;
        $completed = [];
        try {
            $head = $client->height($chain);
            $ready = [];
            foreach ($channels as $c) {
                try {
                    if (app(DepositChannelPolicy::class)->error($c->currency_id, $c->network_id, false, null, true)) throw new RuntimeException('DEPOSIT_CHANNEL_UNAVAILABLE');
                    $query = WalletAddress::whereIn('network_id', config('deposits.evm.' . $chain . '.networks'))->has('user');
                    if ($c->isPilot()) $query->whereIn('user_id', $c->pilotUsers());
                    $addresses = $query->get()->unique(fn($a) => strtolower($a->address))->keyBy(fn($a) => strtolower($a->address))->all();
                    ksort($addresses);
                    foreach (array_keys($addresses) as $address) if (!preg_match('/^0x[0-9a-f]{40}$/', $address)) throw new RuntimeException('DEPOSIT_INVALID_ID');
                    $identity = ['chain' => $chain, 'scope' => $c->scanScope()];
                    DB::table('chain_deposit_scan_states')->insertOrIgnore($identity + ['updated_at' => now()]);
                    $ready[] = ['channel' => $c, 'identity' => $identity, 'addresses' => $addresses];
                } catch (\Throwable $e) {
                    $ok = false;
                    $this->recordFailure($c, $e, $lane);
                    if ($throw) throw $e;
                }
            }
            $pages = $lane === 'all' ? 20 : (int) config('deposits.scan.' . $lane . '_pages');
            for ($page = 0; $page < $pages && $ready && microtime(true) < $stopAt; $page++) {
                $groups = [];
                foreach ($ready as $entry) {
                    $plan = $this->plan($entry, $head, $lane);
                    if (!$plan) continue;
                    $c = $entry['channel'];
                    $key = implode(':', [$c->kind, $c->confirmations, $plan['from'], $plan['to'], hash('sha256', json_encode(array_keys($entry['addresses'])))]);
                    $groups[$key][] = $entry + $plan;
                }
                if (!$groups) break;
                if ($lane === 'backfill') uasort($groups, fn($a, $b) => strcmp((string) $a[0]['state']->backfill_attempted_at, (string) $b[0]['state']->backfill_attempted_at));
                foreach ($groups as $entries) {
                    if (microtime(true) >= $stopAt) break 2;
                    try {
                        if ($lane === 'backfill') foreach ($entries as $e) DB::table('chain_deposit_scan_states')->where($e['identity'])->update(['backfill_attempted_at' => now()]);
                        $proofs = $this->discover($entries, $head, $lane, $client, $explorer, $discovery);
                        foreach ($entries as $entry) {
                            $this->commitPage($entry, $proofs[$entry['channel']->id] ?? [], $lane);
                            $completed[$entry['channel']->id] = true;
                        }
                    } catch (\Throwable $e) {
                        // A bounded historical round yields normally. The uncommitted
                        // page will resume unchanged; do not turn its deadline into a failure.
                        if ($lane === 'backfill' && $e->getMessage() === 'DEPOSIT_SCAN_BUDGET') {
                            if ($throw) throw $e;
                            break 2;
                        }
                        $ok = false;
                        foreach ($entries as $entry) {
                            $this->recordFailure($entry['channel'], $e, $lane);
                            if ($lane === 'backfill' && $e->getMessage() === 'DEPOSIT_LOG_RANGE_TOO_LARGE') {
                                DB::table('chain_deposit_scan_states')->where($entry['identity'])->update(['backfill_range_cap' => max(1, intdiv($entry['to'] - $entry['from'] + 1, 2))]);
                            }
                        }
                        if ($throw) throw $e;
                        if ($e->getMessage() === 'DEPOSIT_SCAN_BUDGET') break 2;
                        $bad = array_column(array_map(fn($e) => ['id' => $e['channel']->id], $entries), 'id');
                        $ready = array_values(array_filter($ready, fn($e) => !in_array($e['channel']->id, $bad, true)));
                    }
                }
            }
        } catch (\Throwable $e) {
            $ok = false;
            foreach ($channels as $c) $this->recordFailure($c, $e, $lane);
            if ($throw) throw $e;
        } finally {
            Log::info('EVM scan round', ['chain' => $chain, 'lane' => $lane, 'completed_channels' => count($completed), 'ok' => $ok] + $client->endRound());
        }
        return $ok;
    }

    private function plan(array $entry, int $head, string $lane): ?array
    {
        $c = $entry['channel'];
        $end = $head - $c->confirmations + 1;
        $state = DB::table('chain_deposit_scan_states')->where($entry['identity'])->first();
        $from = $state->scanned_through === null ? ($c->isPilot() ? $c->pilot_start_block : $c->start_block) : (int) $state->scanned_through + 1;
        if ($from === null) throw new RuntimeException('DEPOSIT_START_BLOCK_REQUIRED');
        // Preserve the old contiguous cursor. A second cursor cannot conceal an unscanned gap.
        if ($lane !== 'all' && $state->realtime_from === null && $end - $from + 1 > (int) config('deposits.scan.live_window')) {
            $split = $end - (int) config('deposits.scan.live_window') + 1;
            DB::table('chain_deposit_scan_states')->where($entry['identity'])->whereNull('realtime_from')->update(['realtime_from' => $split, 'realtime_through' => $split - 1, 'window_end' => $end]);
            $state = DB::table('chain_deposit_scan_states')->where($entry['identity'])->first();
        }
        $target = $end;
        if ($lane === 'backfill') {
            if ($state->realtime_from === null) return null;
            $target = min($end, $state->realtime_from - 1);
        } elseif ($lane === 'live' && $state->realtime_from !== null) {
            $from = $state->realtime_through + 1;
        }
        if ($from > $target) {
            if ($lane !== 'backfill') DB::table('chain_deposit_scan_states')->where($entry['identity'])->update(['window_end' => $end, 'last_success_at' => now(), 'last_error' => null, 'realtime_last_success_at' => now(), 'realtime_last_error' => null, 'updated_at' => now()]);
            return null;
        }
        $size = (int) config('deposits.scan.' . ($c->kind === 'native' ? 'native_range' : 'token_range'));
        if ($c->kind === 'native') $size = min(25, max(1, (int) config('deposits.scan.native_range_by_chain.' . $c->chain, $size)));
        if ($c->kind === 'token' && config('deposits.explorer.recipient_filter') && config('deposits.evm.' . $c->chain . '.logs_provider') === 'etherscan') $size = 5000;
        if ($c->chain !== 'xlayer' && $lane === 'backfill' && $c->kind === 'token') $size = (int) config('deposits.scan.backfill_token_range');
        if ($c->chain !== 'xlayer' && $lane === 'backfill' && $c->kind === 'token' && config('deposits.explorer.recipient_filter') && trim((string) config('deposits.explorer.key'))) $size = (int) config('deposits.scan.backfill_indexed_range', 1000000);
        if ($c->chain !== 'xlayer' && $lane === 'backfill' && $c->kind === 'native' && config('deposits.explorer.native_backfill') && trim((string) config('deposits.explorer.key'))) $size = (int) config('deposits.scan.backfill_native_range');
        if ($lane === 'backfill' && $state->backfill_range_cap !== null) $size = min($size, max(1, (int) $state->backfill_range_cap));
        return ['from' => (int) $from, 'to' => min($target, $from + max(1, $size) - 1), 'end' => $end, 'state' => $state];
    }

    private function discover(array $entries, int $head, string $lane, EvmDepositClient $client, EvmExplorerClient $explorer, EvmLogDiscovery $discovery): array
    {
        $first = $entries[0];
        $chain = $first['channel']->chain;
        $candidates = [];
        if ($first['channel']->kind === 'token') {
            $contracts = array_map(fn($e) => $e['channel']->contract, $entries);
            foreach ($discovery->logs($chain, $contracts, array_keys($first['addresses']), $first['from'], $first['to']) as $log) {
                $address = strtolower('0x' . substr($log['topics'][2], 26));
                foreach ($entries as $entry) if (strtolower($entry['channel']->contract) === strtolower($log['address'])) $candidates[$entry['channel']->id][$address][strtolower($log['transactionHash'])][ChainAmount::integer($log['logIndex'])] = true;
            }
        } elseif ($lane === 'backfill' && config('deposits.explorer.native_backfill') && $explorer->available($chain)) {
            foreach (array_keys($first['addresses']) as $address) {
                foreach ($explorer->nativeTransactions($chain, $address, $first['from'], $first['to']) as $hash) foreach ($entries as $entry) $candidates[$entry['channel']->id][$address][$hash] = true;
            }
        } else {
            foreach ($client->blocks($chain, $first['from'], $first['to']) as $body) {
                foreach ($body['transactions'] as $tx) {
                    $address = strtolower($tx['to'] ?? '');
                    if (!isset($first['addresses'][$address]) || ChainAmount::integer($tx['value'] ?? '0x0') === '0') continue;
                    foreach ($entries as $entry) $candidates[$entry['channel']->id][$address][$tx['hash']] = true;
                }
            }
        }
        $proofs = [];
        foreach ($entries as $entry) {
            $c = $entry['channel'];
            foreach ($candidates[$c->id] ?? [] as $address => $hashes) {
                foreach ($hashes as $hash => $indices) {
                    $verified = $client->verify($c, $hash, $entry['addresses'][$address]->address, $head);
                    if (!$verified || is_array($indices) && array_diff(array_keys($indices), array_column($verified, 'event_index'))) throw new RuntimeException('DEPOSIT_DISCOVERY_RECEIPT_MISMATCH');
                    foreach ($verified as $proof) {
                        if ($proof['block'] < $entry['from'] || $proof['block'] > $entry['to']) throw new RuntimeException('DEPOSIT_DISCOVERY_RECEIPT_MISMATCH');
                        $proofs[$c->id][] = [$entry['addresses'][$address], $proof];
                    }
                }
            }
        }
        return $proofs;
    }

    private function commitPage(array $entry, array $proofs, string $lane): void
    {
        DB::transaction(function () use ($entry, $proofs, $lane) {
            $state = DB::table('chain_deposit_scan_states')->where($entry['identity'])->lockForUpdate()->first();
            foreach (['scanned_through', 'realtime_from', 'realtime_through'] as $field) if ($state->$field !== $entry['state']->$field) throw new RuntimeException('DEPOSIT_CURSOR_CHANGED');
            $c = DepositChannel::lockForUpdate()->findOrFail($entry['channel']->id);
            if ($c->digest() !== $entry['channel']->digest() || $c->scanScope() !== $entry['identity']['scope'] || app(DepositChannelPolicy::class)->error($c->currency_id, $c->network_id, false, null, true)) throw new RuntimeException('DEPOSIT_CHANNEL_UNAVAILABLE');
            foreach ($proofs as [$address, $proof]) app(\App\Services\Deposit\DepositReview::class)->process($c, $address, $proof);
            $update = ['window_end' => $entry['end'], 'last_success_at' => now(), 'last_error' => null, 'updated_at' => now()];
            if ($lane !== 'backfill' && $entry['to'] >= $entry['end']) { $update['realtime_last_success_at'] = now(); $update['realtime_last_error'] = null; }
            if ($lane === 'live' && $state->realtime_from !== null) $update['realtime_through'] = $entry['to'];
            else {
                $update['scanned_through'] = $entry['to'];
                // A round deadline is not a provider range limit. Recover old shrunken caps
                // only after an entire verified page commits; never advance over a gap.
                if ($lane === 'backfill' && $state->backfill_range_cap !== null) {
                    $maximum = (int) config('deposits.scan.backfill_indexed_range', 1000000);
                    $update['backfill_range_cap'] = min($maximum, max(1, (int) $state->backfill_range_cap) * 2);
                }
                if ($state->realtime_from !== null && $entry['to'] >= $state->realtime_from - 1) {
                    $update['scanned_through'] = max($entry['to'], $state->realtime_through);
                    $update['realtime_from'] = $update['realtime_through'] = null;
                }
            }
            DB::table('chain_deposit_scan_states')->where($entry['identity'])->update($update);
        });
    }

    private function recordFailure(DepositChannel $c, \Throwable $e, string $lane): void
    {
        $code = preg_match('/^DEPOSIT_[A-Z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'DEPOSIT_SCAN_EXCEPTION';
        DB::table('chain_deposit_scan_states')->updateOrInsert(['chain' => $c->chain, 'scope' => $c->scanScope()], ['last_error' => $code, 'updated_at' => now()] + ($lane !== 'backfill' ? ['realtime_last_error' => $code] : []));
        Log::error('EVM deposit scanner failed', ['channel_id' => $c->id, 'code' => $code]);
    }
}
