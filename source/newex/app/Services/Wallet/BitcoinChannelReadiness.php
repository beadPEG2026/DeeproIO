<?php

namespace App\Services\Wallet;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only checks before opening BTC. Never allocates an address or signs a transfer. */
final class BitcoinChannelReadiness
{
    public function inspect(): array
    {
        $health = app(BitcoinRpcHealth::class)->inspect();
        $checks = ['application_writable' => !config('app.readonly')];
        foreach (['wallet_rpc', 'wallet_chain_synced', 'wallet_loaded', 'wallet_can_sign'] as $key) {
            $checks[$key] = (bool) ($health[$key] ?? false);
        }
        $manager = app(BitcoinWalletManager::class);
        $active = $manager->active();
        $name = $active->name ?? DB::table('bitcoin_wallet_control')->where('id', 1)->value('legacy_wallet_name');
        $checks['wallet_ready'] = false;
        $checks['reference_address_owned'] = false;
        $funded = null;
        try {
            $rpc = app(BitcoinWalletRpc::class);
            $rpc->ready($name, true);
            $checks['wallet_ready'] = true;
            $sender = $manager->sender();
            if ($sender !== '') {
                $info = $rpc->call('getaddressinfo', [$sender], $name);
                $checks['reference_address_owned'] = ($info['ismine'] ?? false) === true
                    && ($info['iswatchonly'] ?? false) !== true && ($info['address'] ?? null) === $sender;
            }
            $balances = $rpc->call('getbalances', [], $name);
            $funded = bccomp(BitcoinWalletRpc::amount($balances['mine']['trusted'] ?? 0), '0', 8) > 0;
        } catch (\Throwable $e) {
            // RPC messages and endpoint URLs can contain wallet credentials.
        }
        if ($active) {
            $channel = DB::table('custody_networks')->where('chain', 'bitcoin')->first();
            $checks['managed_channel_enabled'] = $channel && $channel->enabled
                && bccomp((string) $channel->max_fee, '0', 18) > 0;
            $checks['active_wallet_scanned'] = (bool) $active->scan_enabled;
            $checks['active_wallet_backed_up'] = !empty($active->backup_at);
        }
        return ['ready' => !in_array(false, $checks, true), 'mode' => $active ? 'managed' : 'existing_core_wallet',
            'checks' => $checks, 'wallet_has_confirmed_funds' => $funded, 'health' => $health,
            'financial_transactions_executed' => false];
    }

    public function assertReady(): array
    {
        $result = $this->inspect();
        if (!$result['ready']) throw new RuntimeException('BTC_CHANNEL_NOT_READY');
        return $result;
    }

    public static function scannerReady(array $result): bool
    {
        if (($result['status'] ?? null) !== 'dry_run') return false;
        // Managed scans report per-wallet errors even when the outer run is a dry run.
        if (isset($result['wallets'])) {
            if (!$result['wallets']) return false;
            foreach ($result['wallets'] as $wallet) if (($wallet['status'] ?? null) !== 'ok') return false;
        }
        return true;
    }
}
