<?php

namespace App\Services\Deposit;

use App\Models\Deposit\DepositChannel;
use Illuminate\Support\Facades\DB;
final class DepositChannelPolicy
{
    public const NETWORKS = [2 => ['ethereum', 'native'], 3 => ['ethereum', 'token'], 5 => ['bsc', 'native'], 6 => ['bsc', 'token'], 8 => ['tron', 'token'], 15 => ['polygon', 'native'], 16 => ['polygon', 'token'], 20 => ['solana', 'native'], 24 => ['xlayer', 'native'], 25 => ['xlayer', 'token'],];
    public function configurationError(DepositChannel $c): ?string
    {
        if ($c->currency && \App\Services\Market\AssetProfile::bscOnly($c->currency) && (int)$c->network_id !== NETWORK_BEP) {
            return 'This asset supports BSC (BEP20) only';
        }
        if ((self::NETWORKS[(int) $c->network_id] ?? null) !== [$c->chain, $c->kind]) {
            return 'Unsupported scanner';
        }
        if ($c->decimals < 0 || $c->decimals > 18 || $c->confirmations < 1) {
            return 'Invalid precision or confirmations';
        }
        if ($c->kind === 'token') {
            try {
                if ($c->chain === 'tron') {
                    TronGridClient::hexAddress((string) $c->contract);
                } elseif (!preg_match('/^0x[0-9a-fA-F]{40}$/', (string) $c->contract) || strtolower($c->contract) === '0x' . str_repeat('0', 40)) {
                    return 'Invalid token contract';
                }
            } catch (\Throwable $e) {
                return 'Invalid token contract';
            }
            $field = [3=>'contract',6=>'bep_contract',8=>'trc_contract',16=>'matic_contract', 25 => 'xlayer_contract'][(int)$c->network_id] ?? null;
            $expected = $field ? (string)$c->currency?->{$field} : '';
            if (!$expected || ($c->chain === 'tron' ? $expected !== $c->contract : strtolower($expected) !== strtolower($c->contract))) {
                return 'Invalid token contract';
            }
        } else {
            $symbol = ['ethereum' => 'ETH', 'bsc' => 'BNB', 'polygon' => 'POL', 'xlayer' => 'OKB', 'solana' => 'SOL'][$c->chain] ?? null;
            if ($c->contract || $c->decimals !== ($c->chain === 'solana' ? 9 : 18) || $c->currency?->symbol !== $symbol) {
                return 'Invalid native asset configuration';
            }
        }
        foreach (['minimum', 'fee_fixed', 'fee_percent'] as $field) {
            if (!is_numeric($c->{$field}) || bccomp((string) $c->{$field}, '0', 18) < 0) {
                return 'Invalid deposit limits';
            }
        }
        if (bccomp((string) $c->fee_fixed, '0', 18) > 0 && bccomp((string) $c->fee_percent, '0', 6) > 0) {
            return 'Choose a fixed fee or a percentage fee';
        }
        if (bccomp((string) $c->fee_percent, '100', 6) >= 0) {
            return 'Invalid deposit fee';
        }
        if ($c->chain === 'solana' && $c->confirmations !== 1) return 'Solana requires finalized commitment';
        if (!in_array($c->chain, ['tron', 'solana'], true) && $c->start_block === null) {
            return 'Scanner start block required';
        }
        if (!DB::table('currency_networks')->where('currency_id', $c->currency_id)->where('network_id', $c->network_id)->exists()) {
            return 'Asset is not linked to this network';
        }
        return null;
    }
    public function error(int $currencyId, int $networkId, bool $health = true, ?int $userId = null, bool $scanner = false): ?string
    {
        $currency = \App\Models\Currency\Currency::find($currencyId);
        $network = \App\Models\Network\Network::find($networkId);
        if (!$currency || !$currency->status || !$currency->deposit_status || !$network || !$network->status || !$network->deposit_status) {
            return 'Deposits are unavailable';
        }
        if ($error=\App\Services\Market\AssetProfile::networkError($currency,$network->slug)) return $error;
        if (in_array($networkId, array_map('intval', is_array($currency->disabled_deposit_networks) ? $currency->disabled_deposit_networks : []), true)) {
            return 'Deposits are unavailable';
        }
        if (!DB::table('currency_networks')->where('currency_id', $currencyId)->where('network_id', $networkId)->exists()) {
            return 'Asset is not linked to this network';
        }
        // Native BTC uses Bitcoin Core wallet history, not the EVM channel table.
        if ($network->slug === 'btc' && $currency->symbol === 'BTC') {
            if (!config('bitcoind.default.host') || !config('bitcoind.default.user') || !config('bitcoind.default.password')) {
                return 'Scanner credential is missing';
            }
            if ($health) {
                $state = \Illuminate\Support\Facades\Cache::get(BitcoinWalletScanner::STATE, []);
                try {
                    if (($state['mode']??null)==='managed') {
                        $required=DB::table('bitcoin_wallets')->where('scan_enabled',true)->orderBy('id')->pluck('id')->map(fn($id)=>(int)$id)->all();
                        $seen=array_map(fn($w)=>(int)$w['wallet_id'],$state['wallets']??[]);sort($seen);
                        $active=(int)DB::table('bitcoin_wallet_control')->where('id',1)->value('active_wallet_id');
                        if(($state['status']??null)!=='ok'||!$required||$required!==$seen||!in_array($active,$required,true))return 'Deposit scanner needs attention';
                    }
                    $checked = isset($state['checked_at']) ? \Carbon\Carbon::parse($state['checked_at']) : null;
                    if (!$checked || $checked->isFuture() || $checked->lt(now()->subMinutes(config('deposits.max_scan_age_minutes', 15))) || empty($state['height'])) {
                        return 'Deposit scanner needs attention';
                    }
                } catch (\Throwable $e) { return 'Deposit scanner needs attention'; }
            }
            return null;
        }
        // Native TRX retains its previously accepted, receipt-backed scanner.
        if ($networkId === NETWORK_TRX && $currency->symbol === 'TRX') {
            if (!trim((string) config('services.trongrid.key'))) {
                return 'Scanner credential is missing';
            }
            if ($health && !DB::table('tron_deposit_scan_states')->whereNotNull('last_success_at')->where('last_success_at', '>=', now()->subMinutes(15))->whereNull('last_error')->exists()) {
                return 'Deposit scanner needs attention';
            }
            return null;
        }
        $c = DepositChannel::where('currency_id', $currencyId)->where('network_id', $networkId)->first();
        if (!$c) {
            return 'Deposit channel is not configured';
        }
        if ($error = $this->configurationError($c)) {
            return $error;
        }
        if (!hash_equals((string) $c->config_digest, $c->digest())) {
            return 'Deposit channel is awaiting acceptance';
        }
        if ($c->isPilot()) {
            if (!$c->pilot_started_at || !$c->pilot_expires_at || !$c->pilot_digest || !hash_equals($c->pilot_digest, $c->pilotDigest()) || !$c->pilotUsers() || bccomp((string) ($c->pilot_minimum ?? 0), '0', 18) <= 0 || bccomp((string) ($c->pilot_limit ?? 0), (string) $c->pilot_minimum, 18) < 0 || (!in_array($c->chain, ['tron','solana'], true) && $c->pilot_start_block === null)) return 'Deposit channel is awaiting acceptance';
            if (!$scanner && !in_array($userId, $c->pilotUsers(), true)) return 'Deposits are unavailable';
            // Discovery continues for delayed confirmations; credit checks the receipt timestamp.
            if (!$scanner && $health && $c->pilot_expires_at->isPast()) return 'Deposits are unavailable';
        } elseif ($c->state !== 'active' || !$c->acceptance_reference) {
            return 'Deposit channel is awaiting acceptance';
        }
        $provider = match ($c->chain) { 'tron' => config('services.trongrid.key'), 'solana' => config('solana.rpc_endpoint'), default => config('deposits.evm.' . $c->chain . '.rpc') };
        if (!trim((string) $provider)) {
            return 'Scanner credential is missing';
        }
        if ($health) {
            $state = DB::table('chain_deposit_scan_states')->where('chain', $c->chain)->where('scope', $c->scanScope())->first();
            if (!$state) return 'Deposit scanner needs attention';
            if (!in_array($c->chain, ['tron','solana'], true) && $state->realtime_from !== null) {
                // A completed recent window serves new deposits while the old contiguous cursor backfills.
                if (!$state->realtime_last_success_at || $state->realtime_last_error || $state->realtime_through < $state->realtime_from || \Carbon\Carbon::parse($state->realtime_last_success_at)->lt(now()->subMinutes(config('deposits.max_scan_age_minutes', 15)))) return 'Deposit scanner needs attention';
            } elseif (!in_array($c->chain, ['tron','solana'], true) && $state->window_end !== null && $state->scanned_through < $state->window_end || !$state->last_success_at || $state->last_error || \Carbon\Carbon::parse($state->last_success_at)->lt(now()->subMinutes(config('deposits.max_scan_age_minutes', 15)))) {
                return 'Deposit scanner needs attention';
            }
        }
        return null;
    }
}
