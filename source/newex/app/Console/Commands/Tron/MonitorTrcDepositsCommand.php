<?php

namespace App\Console\Commands\Tron;

use App\Models\Deposit\DepositChannel;
use App\Models\Wallet\WalletAddress;
use App\Services\Deposit\{TronGridClient, VerifiedChainDeposit, DepositChannelPolicy};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};
use RuntimeException;
final class MonitorTrcDepositsCommand extends Command
{
    protected $signature = 'tron:monitor-trc-deposits';
    protected $description = 'Scan receipt-verified TRC20 transfers with persistent pagination';
    public function handle(): int
    {
        if (!DB::selectOne('SELECT pg_try_advisory_lock(77321,8) AS acquired')->acquired) {
            return self::SUCCESS;
        }
        try {
            $channels = DepositChannel::where('chain', 'tron')->whereIn('state', ['active', 'pilot', 'tested'])->get();
            $failed = false;
            $groups = [];
            $public = $channels->where('state', 'active');
            if ($public->isNotEmpty()) $groups[] = [$public, null];
            foreach ($channels->filter(fn($c) => $c->isPilot()) as $pilot) $groups[] = [collect([$pilot]), $pilot];
            foreach ($groups as [$group, $pilot]) {
                $groupFailed = false;
                foreach ($group as $c) {
                    if (app(DepositChannelPolicy::class)->error($c->currency_id, $c->network_id, false, null, true)) $groupFailed = true;
                }
                $query = WalletAddress::whereIn('network_id', [NETWORK_TRX, NETWORK_TRC])->has('user')->orderBy('id');
                if ($pilot) $query->whereIn('user_id', $pilot->pilotUsers());
                if (!$groupFailed) foreach ($query->get()->unique('address') as $address) {
                    try { $this->check($address, $pilot); }
                    catch (\Throwable $e) {
                        $groupFailed = true;
                        $code = preg_match('/^(TRON|DEPOSIT)[A-Z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'TRON_SCAN_EXCEPTION';
                        DB::table('chain_deposit_scan_states')->updateOrInsert(['chain' => 'tron', 'scope' => $this->scope($address, $pilot)], ['last_error' => $code, 'updated_at' => now()]);
                        Log::error('TRC20 scanner failed', ['address_id' => $address->id, 'code' => $code]);
                        $this->error($code);
                    }
                }
                $prefix = $pilot ? 'pilot:' . $pilot->id . ':' . $pilot->pilot_digest . ':address:%' : 'address:%';
                if (DB::table('chain_deposit_scan_states')->where('chain', 'tron')->where('scope', 'like', $prefix)->whereNotNull('fingerprint')->exists()) $groupFailed = true;
                foreach ($group as $c) DB::table('chain_deposit_scan_states')->updateOrInsert(['chain' => 'tron', 'scope' => $c->scanScope()], $groupFailed ? ['last_error' => 'TRON_SCAN_INCOMPLETE', 'updated_at' => now()] : ['last_success_at' => now(), 'last_error' => null, 'updated_at' => now()]);
                $failed = $failed || $groupFailed;
            }
            return $failed ? self::FAILURE : self::SUCCESS;
        } finally {
            DB::select('SELECT pg_advisory_unlock(77321,8)');
        }
    }
    private function scope(WalletAddress $address, ?DepositChannel $pilot): string
    {
        return $pilot ? 'pilot:' . $pilot->id . ':' . $pilot->pilot_digest . ':address:' . $address->id : 'address:' . $address->address;
    }
    public function check(WalletAddress $address, ?DepositChannel $pilot = null): void
    {
        if ($pilot && (!$pilot->isPilot() || !in_array((int) $address->user_id, $pilot->pilotUsers(), true))) throw new RuntimeException('DEPOSIT_PILOT_ACCOUNT_NOT_ALLOWED');
        $identity = ['chain' => 'tron', 'scope' => $this->scope($address, $pilot)];
        DB::table('chain_deposit_scan_states')->insertOrIgnore($identity + ['updated_at' => now()]);
        for ($page = 0; $page < 5; $page++) {
            $more = DB::transaction(function () use ($address, $identity, $pilot) {
                $state = DB::table('chain_deposit_scan_states')->where($identity)->lockForUpdate()->first();
                $start = $state->window_start ?? max($address->created_at->getTimestamp() * 1000, $pilot ? $pilot->pilot_started_at->getTimestamp() * 1000 : 0, ($state->scanned_through ?? 0) - 86400000);
                $end = $state->window_end ?? min(now()->subMinute()->getTimestamp() * 1000, $pilot ? $pilot->pilot_expires_at->getTimestamp() * 1000 : PHP_INT_MAX);
                if ($end < $start) {
                    return false;
                }
                $query = ['only_confirmed' => 'true', 'only_to' => 'true', 'limit' => 20, 'order_by' => 'block_timestamp,asc', 'min_timestamp' => $start, 'max_timestamp' => $end];
                if ($state->fingerprint) {
                    $query['fingerprint'] = $state->fingerprint;
                }
                $data = app(TronGridClient::class)->request('v1/accounts/' . $address->address . '/transactions/trc20', $query);
                if (!isset($data['data']) || !is_array($data['data'])) {
                    throw new RuntimeException('TRONGRID_INVALID_INDEX');
                }
                $seen = [];
                foreach ($data['data'] as $event) {
                    if (($event['type'] ?? '') !== 'Transfer' || ($event['to'] ?? '') !== $address->address) {
                        continue;
                    }
                    $hash = $event['transaction_id'] ?? '';
                    if (isset($seen[$hash])) {
                        continue;
                    }
                    $seen[$hash] = true;
                    // Inspect every actual receipt log, including multiple identical transfers in one transaction.
                    foreach (app(TronGridClient::class)->tokenTransfers($hash, $address->address) as $proof) {
                        $channels = $pilot ? collect($pilot->contract === $proof['contract'] ? [$pilot] : []) : DepositChannel::where('chain', 'tron')->where('kind', 'token')->where('contract', $proof['contract'])->where('state', 'active')->get();
                        if ($channels->count() > 1) {
                            throw new RuntimeException('DEPOSIT_AMBIGUOUS_CONTRACT');
                        }
                        if ($channels->isEmpty()) {
                            DB::table('unrecognized_deposit_events')->insertOrIgnore(['chain' => 'tron', 'txn' => $hash, 'event_index' => $proof['event_index'], 'address' => $address->address, 'contract' => $proof['contract'], 'raw_amount' => $proof['raw_amount'], 'reason' => 'UNLISTED_OR_INACTIVE_CONTRACT', 'evidence' => json_encode($proof), 'created_at' => now()]);
                            continue;
                        }
                        if (app(MonitorTrxDepositsCommand::class)->shouldExcludeFromAddress($proof['sender'])) {
                            continue;
                        }
                        app(VerifiedChainDeposit::class)->process($channels->first(), $address, $proof);
                    }
                }
                $next = $data['meta']['fingerprint'] ?? null;
                if ($next && $next === $state->fingerprint) {
                    throw new RuntimeException('TRONGRID_STALLED_CURSOR');
                }
                DB::table('chain_deposit_scan_states')->where($identity)->update(['window_start' => $next ? $start : null, 'window_end' => $next ? $end : null, 'fingerprint' => $next, 'scanned_through' => $next ? $state->scanned_through : $end, 'last_success_at' => now(), 'last_error' => null, 'updated_at' => now()]);
                return (bool) $next;
            });
            if (!$more) {
                break;
            }
        }
    }
}
