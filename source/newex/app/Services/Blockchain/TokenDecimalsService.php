<?php

namespace App\Services\Blockchain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenDecimalsService
{
    protected const CACHE_PREFIX = 'token_decimals';

    public function divisor(int $networkId, ?string $contract, $providedDecimals = null, ?int $fallback = null): string
    {
        $decimals = $this->resolve($networkId, $contract, $providedDecimals, $fallback);

        return bcpow('10', (string) $decimals, 0);
    }

    public function resolve(int $networkId, ?string $contract, $providedDecimals = null, ?int $fallback = null): int
    {
        $provided = $this->normalizeDecimals($providedDecimals);

        if ($provided !== null) {
            $this->putCachedDecimals($networkId, $contract, $provided);

            return $provided;
        }

        $cached = $this->getCachedDecimals($networkId, $contract);

        if ($cached !== null) {
            return $cached;
        }

        $fetched = $this->fetchDecimals($networkId, $contract);

        if ($fetched !== null) {
            $this->putCachedDecimals($networkId, $contract, $fetched);

            return $fetched;
        }

        return $fallback ?? $this->defaultDecimals($networkId);
    }

    protected function fetchDecimals(int $networkId, ?string $contract): ?int
    {
        if (!$contract) {
            return null;
        }

        if (in_array($networkId, [NETWORK_ERC, NETWORK_BEP, NETWORK_MATIC20, NETWORK_XLAYER20, NETWORK_CUSTOMTOKEN_TOKEN], true)) {
            return $this->fetchEvmDecimals($networkId, $contract);
        }

        if ($networkId === NETWORK_TRC) {
            return $this->fetchTronDecimals($contract);
        }

        return null;
    }

    protected function fetchEvmDecimals(int $networkId, string $contract): ?int
    {
        $rpcUrl = $this->evmRpcUrl($networkId);

        if (!$rpcUrl) {
            return null;
        }

        try {
            $response = Http::timeout(6)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_call',
                'params' => [
                    [
                        'to' => $contract,
                        'data' => '0x313ce567',
                    ],
                    'latest',
                ],
            ]);

            if (!$response->successful()) {
                return null;
            }

            $result = $response->json('result');

            if (!is_string($result) || $result === '' || $result === '0x') {
                return null;
            }

            $hex = preg_replace('/^0x/i', '', $result);

            if (!$hex) {
                return null;
            }

            return $this->normalizeDecimals((string) hexdec($hex));
        } catch (\Throwable $e) {
            Log::warning('Fetch EVM token decimals failed', [
                'network_id' => $networkId,
                'contract' => $contract,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function fetchTronDecimals(string $contract): ?int
    {
        try {
            $response = Http::withHeaders($this->tronscanHeaders())->timeout(6)->get(env('APP_TRONSCAN_API', 'https://apilist.tronscan.org') . '/api/token_trc20', [
                'contract' => $contract,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            foreach ([
                data_get($data, 'trc20_tokens.0.decimals'),
                data_get($data, 'data.0.decimals'),
                data_get($data, 'tokenInfo.tokenDecimal'),
                data_get($data, 'tokenDecimal'),
                data_get($data, 'decimals'),
            ] as $candidate) {
                $decimals = $this->normalizeDecimals($candidate);

                if ($decimals !== null) {
                    return $decimals;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Fetch TRON token decimals failed', [
                'contract' => $contract,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function evmRpcUrl(int $networkId): ?string
    {
        return match ($networkId) {
            NETWORK_XLAYER20 => config('deposits.evm.xlayer.rpc'),
            NETWORK_ERC => env('APP_ETHEREUM_RPC_URL')
                ?: env('ETHEREUM_RPC_URL')
                ?: 'https://ethereum.publicnode.com',
            NETWORK_BEP => env('APP_BSC_RPC_URL')
                ?: env('BSC_RPC_URL')
                ?: 'https://bsc-dataseed.binance.org',
            NETWORK_MATIC20 => env('APP_POLYGON_RPC_URL')
                ?: env('POLYGON_RPC_URL')
                ?: 'https://polygon-rpc.com',
            NETWORK_CUSTOMTOKEN_TOKEN => env('APP_CUSTOMTOKEN_RPC_URL')
                ?: env('APP_CUSTOMTOKEN_TOKEN_RPC_URL')
                ?: null,
            default => null,
        };
    }

    protected function tronscanHeaders(): array
    {
        $key = trim((string) (env('APP_TRONSCAN_KEY') ?: env('TRONSCAN_API_KEY')));

        return $key !== '' ? ['TRON-PRO-API-KEY' => $key] : [];
    }

    protected function defaultDecimals(int $networkId): int
    {
        return match ($networkId) {
            NETWORK_BTC, NETWORK_BTC_FORK, NETWORK_BRC20 => 8,
            NETWORK_TRX => 6,
            NETWORK_ETH, NETWORK_ERC, NETWORK_BNB, NETWORK_BEP, NETWORK_MATIC, NETWORK_MATIC20, NETWORK_XLAYER, NETWORK_XLAYER20, NETWORK_CUSTOMTOKEN_NETWORK, NETWORK_CUSTOMTOKEN_TOKEN => 18,
            default => 18,
        };
    }

    protected function getCachedDecimals(int $networkId, ?string $contract): ?int
    {
        $key = $this->cacheKey($networkId, $contract);

        if (!$key) {
            return null;
        }

        try {
            return $this->normalizeDecimals(Cache::get($key));
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function putCachedDecimals(int $networkId, ?string $contract, int $decimals): void
    {
        $key = $this->cacheKey($networkId, $contract);

        if (!$key) {
            return;
        }

        try {
            Cache::forever($key, $decimals);
        } catch (\Throwable $e) {
            // Decimals are still usable for the current scan even if cache is temporarily unavailable.
        }
    }

    protected function cacheKey(int $networkId, ?string $contract): ?string
    {
        $contract = trim((string) $contract);

        if ($contract === '') {
            return null;
        }

        return self::CACHE_PREFIX . ':' . $networkId . ':' . sha1($contract);
    }

    protected function normalizeDecimals($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $decimals = (int) $value;

        if ($decimals < 0 || $decimals > 36) {
            return null;
        }

        return $decimals;
    }
}
