<?php

namespace App\Services\Deposit;

use App\Models\Deposit\DepositChannel;
use App\Models\Wallet\WalletAddress;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Node reads only: never advances a checkpoint or credits a wallet. */
final class DepositChannelReadiness
{
    private array $starts = [];

    public function inspect(DepositChannel $c): array
    {
        $error = app(DepositChannelPolicy::class)->configurationError($c);
        if ($error && $error !== 'Scanner start block required') throw new RuntimeException($error);
        $out = ['channel_id' => $c->id, 'chain' => $c->chain, 'checked_at' => now()->toIso8601String(), 'state' => $c->state, 'configured_start_block' => $c->start_block];
        if ($c->chain === 'solana') {
            $out += ['chain_identity_valid'=>true, 'head'=>app(SolanaDepositClient::class)->ready(), 'actual_decimals'=>9, 'precision_matches'=>$c->decimals===9, 'suggested_start_block'=>null, 'basis'=>'finalized-signatures-since-address-assignment'];
        } elseif ($c->chain === 'tron') {
            $r = app(TronGridClient::class)->request('wallet/triggerconstantcontract', ['owner_address' => TronGridClient::hexAddress($c->contract), 'contract_address' => TronGridClient::hexAddress($c->contract), 'function_selector' => 'decimals()', 'visible' => false]);
            if (($r['result']['result'] ?? false) !== true) throw new RuntimeException('DEPOSIT_CONTRACT_READ_FAILED');
            $precision = (int) ChainAmount::integer($r['constant_result'][0] ?? '');
            $out += ['actual_decimals' => $precision, 'precision_matches' => $precision === $c->decimals, 'suggested_start_block' => null, 'basis' => 'address-assignment-time-and-persistent-cursor'];
        } else {
            $client = app(EvmDepositClient::class);
            $head = $client->height($c->chain);
            if ($c->kind === 'token') {
                if (in_array($client->rpc($c->chain, 'eth_getCode', [$c->contract, 'latest']), ['0x', '0x0', null], true)) throw new RuntimeException('DEPOSIT_CONTRACT_HAS_NO_CODE');
                $precision = (int) ChainAmount::integer($client->rpc($c->chain, 'eth_call', [['to' => $c->contract, 'data' => '0x313ce567'], 'latest']));
            } else $precision = 18;
            $out += ['chain_identity_valid' => true, 'head' => $head, 'actual_decimals' => $precision, 'precision_matches' => $precision === $c->decimals];
            $earliest = WalletAddress::whereIn('network_id', config('deposits.evm.' . $c->chain . '.networks'))->has('user')->min('created_at');
            $cacheKey = $c->chain . ':' . ($earliest ?? 'empty') . ':' . $c->confirmations;
            if (!isset($this->starts[$cacheKey])) {
                $start = $earliest ? $this->firstBlock($c->chain, \Carbon\Carbon::parse($earliest)->getTimestamp(), $head) : max(0, $head - $c->confirmations + 1);
                $this->starts[$cacheKey] = ['suggested_start_block' => $start, 'earliest_address_at' => $earliest, 'basis' => $earliest ? 'block-before-earliest-address-assignment' : 'no-assigned-addresses-confirmed-head'];
            }
            $out += $this->starts[$cacheKey];
        }
        $out['legacy_deposit_count'] = DB::table('deposits')->where('currency_id', $c->currency_id)->where('network_id', $c->network_id)->where(fn($q) => $q->whereNull('source_id')->orWhere('source_id', 'not like', 'verified:%'))->count();
        $out['has_pilot_receipt'] = $c->hasPilotEvidence();
        return $out;
    }

    private function firstBlock(string $chain, int $timestamp, int $head): int
    {
        // Bracket from the current tip: a recent account must not require genesis-era history.
        $upper = $head;
        if ($this->blockTime($chain, $upper) < $timestamp) return $head;
        $step = min(100, $head); $probes = 0;
        while (true) {
            if (++$probes > 80) throw new RuntimeException('DEPOSIT_HISTORICAL_BLOCK_UNAVAILABLE');
            $lower = max(0, $upper - $step);
            try { $time = $this->blockTime($chain, $lower); }
            catch (\Throwable $e) {
                // A pruned range is not evidence of no deposits. Narrow the probe and fail closed at its boundary.
                if ($step <= 1) throw new RuntimeException('DEPOSIT_HISTORICAL_BLOCK_UNAVAILABLE');
                $step = max(1, intdiv($step, 2));
                continue;
            }
            if ($time < $timestamp) break;
            if ($lower === 0) return 0;
            $upper = $lower;
            $step = min($upper, $step * 2);
        }
        while ($lower + 1 < $upper) {
            $mid = intdiv($lower + $upper, 2);
            if ($this->blockTime($chain, $mid) < $timestamp) $lower = $mid;
            else $upper = $mid;
        }
        return $lower;
    }

    private function blockTime(string $chain, int $number): int
    {
        $block = app(EvmDepositClient::class)->rpc($chain, 'eth_getBlockByNumber', ['0x' . dechex($number), false]);
        if (!is_array($block) || ChainAmount::integer($block['number'] ?? '') !== (string) $number || !isset($block['timestamp'], $block['hash'])) throw new RuntimeException('DEPOSIT_HISTORICAL_BLOCK_UNAVAILABLE');
        return (int) ChainAmount::integer($block['timestamp']);
    }
}
