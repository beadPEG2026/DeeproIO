<?php

namespace App\Services\Ankr;

use Exception;

class AnkrService
{
    protected string $apiKey;
    protected string $rpcUrl;
    protected int $id = 1;
    public const ETHEREUM = 'eth';
    public const BSC      = 'bsc';
    public const POLYGON  = 'polygon';

    public function __construct(
        string $apiKey,
        string $chain = 'eth'
    ) {
        $this->apiKey = $apiKey;
        $this->rpcUrl = "https://rpc.ankr.com/{$chain}/{$apiKey}";
    }

    public static function rpc(string $network, string $apiKey): string
    {
        return match ($network) {
            self::ETHEREUM  => "https://rpc.ankr.com/eth/{$apiKey}",
            self::BSC       => "https://rpc.ankr.com/bsc/{$apiKey}",
            self::POLYGON   => "https://rpc.ankr.com/polygon/{$apiKey}",
            default => throw new \InvalidArgumentException("Unsupported network"),
        };
    }

    /**
     * Generic JSON-RPC call
     */
    public function call(string $method, array $params = [])
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id'      => $this->id++,
            'method'  => $method,
            'params'  => $params,
        ];

        $ch = curl_init($this->rpcUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            throw new Exception(
                $decoded['error']['message'] ?? 'Unknown RPC error'
            );
        }

        return $decoded['result'] ?? null;
    }

    /**
     * Get latest block number
     */
    public function getBlockNumber(): int
    {
        return hexdec($this->call('eth_blockNumber'));
    }

    /**
     * Get native balance (ETH, BNB, MATIC, etc.)
     */
    public function getBalance(string $address): string
    {
        $balanceHex = $this->call(
            'eth_getBalance',
            [$address, 'latest']
        );

        return $this->hexToDec($balanceHex);
    }

    /**
     * Get transaction by hash
     */
    public function getTransaction(string $txHash): ?array
    {
        return $this->call('eth_getTransactionByHash', [$txHash]);
    }

    /**
     * ERC20 token balance
     */
    public function getTokenBalance(
        string $wallet,
        string $tokenContract
    ): string {
        $data = str_pad(
                substr($wallet, 2),
                64,
                '0',
                STR_PAD_LEFT
            );

        $result = $this->call('eth_call', [[
            'to'   => $tokenContract,
            'data' => $data,
        ], 'latest']);

        return $this->hexToDec($result);
    }

    /**
     * Helper: hex → decimal string
     */
    protected function hexToDec(?string $hex): string
    {
        if (!$hex) {
            return '0';
        }

        return function_exists('gmp_init')
            ? gmp_strval(gmp_init($hex, 16), 10)
            : (string) hexdec($hex);
    }


}
