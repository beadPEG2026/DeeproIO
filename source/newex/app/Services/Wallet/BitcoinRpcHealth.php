<?php

namespace App\Services\Wallet;

use Illuminate\Support\Facades\Http;

/** Public chain data and the private Bitcoin Core wallet are separate services. */
final class BitcoinRpcHealth
{
    public function inspect(): array
    {
        $walletUrl = config('bitcoind.default.scheme').'://'.config('bitcoind.default.host').':'.config('bitcoind.default.port');
        $publicUrl = trim((string) config('bitcoind.default.quicknode'));
        $chain = $this->request($publicUrl ?: $walletUrl, 'getblockchaininfo', $publicUrl === '');
        $walletChain = $publicUrl === '' ? $chain : $this->request($walletUrl, 'getblockchaininfo', true);
        $active=app(BitcoinWalletManager::class)->active();
        $name=$active->name??\Illuminate\Support\Facades\DB::table('bitcoin_wallet_control')->where('id',1)->value('legacy_wallet_name');
        $walletPath=$name!==null?'/wallet/'.rawurlencode($name):'';
        $wallet = $this->mainnet($walletChain) ? $this->request($walletUrl.$walletPath, 'getwalletinfo', true) : null;
        $loaded = is_array($wallet) && isset($wallet['walletname']);

        return [
            'rpc' => $this->mainnet($chain),
            'rpc_mode' => $publicUrl === '' ? 'wallet_node' : 'external_chain_query',
            'height' => $this->mainnet($chain) ? $chain['blocks'] : null,
            'chain_synced' => $this->synced($chain),
            'wallet_rpc' => $this->mainnet($walletChain),
            'wallet_chain_synced' => $this->synced($walletChain),
            'wallet_loaded' => $loaded,
            'wallet_can_sign' => $loaded && ($wallet['private_keys_enabled'] ?? false) === true
                && (!isset($wallet['unlocked_until']) || $wallet['unlocked_until'] > time()),
        ];
    }

    private function request(string $url, string $method, bool $wallet): ?array
    {
        try {
            $request = Http::connectTimeout(2)->timeout(5);
            // Never send the private wallet's Basic Auth to an external provider.
            if ($wallet) $request = $request->withBasicAuth((string) config('bitcoind.default.user'), (string) config('bitcoind.default.password'));
            $response = $request->post($url, ['jsonrpc' => '2.0', 'id' => 'readiness', 'method' => $method, 'params' => []]);
            $body = $response->json();
            return $response->successful() && empty($body['error']) && is_array($body['result'] ?? null) ? $body['result'] : null;
        } catch (\Throwable $e) {
            // Provider URLs contain credentials: do not log exception text or URLs.
            return null;
        }
    }

    private function mainnet(?array $data): bool
    {
        return ($data['chain'] ?? null) === 'main' && isset($data['blocks']);
    }

    private function synced(?array $data): bool
    {
        return $this->mainnet($data) && ($data['initialblockdownload'] ?? true) === false
            && ($data['verificationprogress'] ?? 0) >= 0.999;
    }
}
