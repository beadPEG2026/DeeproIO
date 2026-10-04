<?php

namespace App\Services\Deposit;

use App\Models\Deposit\DepositChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;
final class EvmDepositClient
{
    private bool $round = false;
    private array $cache = [];
    private array $checked = [];
    private array $failed = [];
    private int $requests = 0;
    private int $budget = PHP_INT_MAX;
    private array $metrics = [];
    private float $deadline = PHP_FLOAT_MAX;

    public function beginRound(int $budget, float $deadline = PHP_FLOAT_MAX): void
    {
        $this->round = true;
        $this->cache = $this->checked = $this->failed = $this->metrics = [];
        $this->requests = 0;
        $this->budget = $budget;
        $this->deadline = $deadline;
    }
    public function endRound(): array
    {
        $this->round = false;
        $this->cache = $this->checked = $this->failed = [];
        $this->budget = PHP_INT_MAX;
        $this->deadline = PHP_FLOAT_MAX;
        return ['rpc_requests' => $this->requests, 'methods' => $this->metrics];
    }
    private function charge(string $method, int $count = 1): void
    {
        if (microtime(true) >= $this->deadline || $this->requests + $count > $this->budget) throw new RuntimeException('DEPOSIT_SCAN_BUDGET');
        $this->requests += $count;
        $this->metrics[$method] = ($this->metrics[$method] ?? 0) + $count;
    }
    private function urls(string $chain): array
    {
        $urls = array_unique(array_filter(array_map('trim', array_merge([(string) config('deposits.evm.' . $chain . '.rpc')], config('deposits.evm.' . $chain . '.rpc_fallbacks', [])))));
        foreach ($urls as $url) if (!preg_match('#^https?://#', $url)) throw new RuntimeException('DEPOSIT_RPC_MISSING');
        if (!$urls) throw new RuntimeException('DEPOSIT_RPC_MISSING');
        return $urls;
    }
    private function post(string $url, array $payload): mixed
    {
        try {
            $r = Http::acceptJson()->connectTimeout(3)->timeout(15)->post($url, $payload);
            if (!$r->successful()) throw new RuntimeException();
            return $r->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('DEPOSIT_RPC_FAILED');
        }
    }
    private function checkEndpoint(string $chain, string $url): void
    {
        if ($this->round && isset($this->checked[$chain][$url])) return;
        $this->charge('eth_chainId');
        $data = $this->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_chainId', 'params' => []]);
        if (isset($data['error']) || !is_string($data['result'] ?? null)) throw new RuntimeException('DEPOSIT_RPC_FAILED');
        if (ChainAmount::integer($data['result']) !== (string) config('deposits.evm.' . $chain . '.chain_id')) throw new RuntimeException('DEPOSIT_WRONG_CHAIN');
        $this->checked[$chain][$url] = true;
    }
    public function rpc(string $chain, string $method, array $params = []): mixed
    {
        if (!in_array($method, ['eth_chainId', 'eth_blockNumber', 'eth_getTransactionByHash', 'eth_getTransactionReceipt', 'eth_getBlockByNumber', 'eth_getLogs', 'eth_call', 'eth_getCode'], true)) throw new RuntimeException('DEPOSIT_RPC_METHOD_FORBIDDEN');
        $cacheKey = $chain . ':' . $method . ':' . json_encode($params);
        // Cache lifetime is a single locked scan round; canonical block checks never survive it.
        $cacheable = $this->round && in_array($method, ['eth_chainId', 'eth_blockNumber', 'eth_getTransactionReceipt', 'eth_getTransactionByHash', 'eth_getBlockByNumber'], true);
        if ($cacheable && array_key_exists($cacheKey, $this->cache)) return $this->cache[$cacheKey];
        $error = 'DEPOSIT_RPC_FAILED';
        foreach ($this->urls($chain) as $url) {
            if ($this->round && isset($this->failed[$chain][$url])) continue;
            try {
                $this->checkEndpoint($chain, $url);
                if ($method === 'eth_chainId') $value = '0x' . dechex((int) config('deposits.evm.' . $chain . '.chain_id'));
                else {
                    $this->charge($method);
                    $data = $this->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
                    if (!is_array($data) || isset($data['error']) || !array_key_exists('result', $data)) throw new RuntimeException('DEPOSIT_RPC_FAILED');
                    $value = $data['result'];
                    if ($value === null) throw new RuntimeException('DEPOSIT_RPC_FAILED');
                }
                if ($cacheable && $value !== null) $this->cache[$cacheKey] = $value;
                return $value;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'DEPOSIT_SCAN_BUDGET') throw $e;
                $error = $e->getMessage();
                // Logs may exceed a provider's range limit. Let discovery split and retry that endpoint.
                if ($method !== 'eth_getLogs') $this->failed[$chain][$url] = true;
            }
        }
        if ($chain !== 'xlayer' && config('deposits.explorer.proxy_fallback') && in_array($method, ['eth_getTransactionReceipt', 'eth_getTransactionByHash', 'eth_getBlockByNumber'], true)) {
            $this->charge('etherscan_proxy');
            $explorer = app(EvmExplorerClient::class);
            $explorer->setDeadline($this->deadline);
            $value = $explorer->proxy($chain, $method, $params);
            if ($cacheable) $this->cache[$cacheKey] = $value;
            return $value;
        }
        throw new RuntimeException($error);
    }
    /** Batching reduces round trips, not billed RPC method count. */
    public function blocks(string $chain, int $from, int $to): array
    {
        if ($to < $from || $to - $from >= 25) throw new RuntimeException('DEPOSIT_INVALID_BLOCK_RANGE');
        $key = $chain . ':blocks:' . $from . ':' . $to;
        if ($this->round && isset($this->cache[$key])) return $this->cache[$key];
        foreach ($this->urls($chain) as $url) {
            if ($this->round && isset($this->failed[$chain][$url])) continue;
            try {
                $this->checkEndpoint($chain, $url);
                $requests = [];
                for ($n = $from; $n <= $to; $n++) $requests[] = ['jsonrpc' => '2.0', 'id' => $n, 'method' => 'eth_getBlockByNumber', 'params' => ['0x' . dechex($n), true]];
                $this->charge('eth_getBlockByNumber', count($requests));
                $data = $this->post($url, $requests);
                if (!is_array($data) || !array_is_list($data) || count($data) !== count($requests)) throw new RuntimeException('DEPOSIT_BLOCK_BATCH_FAILED');
                $blocks = [];
                foreach ($data as $row) {
                    $id = $row['id'] ?? null;
                    $body = $row['result'] ?? null;
                    if (!is_int($id) || $id < $from || $id > $to || isset($blocks[$id]) || !is_array($body) || isset($row['error']) || !is_array($body['transactions'] ?? null) || ChainAmount::integer($body['number'] ?? '') !== (string) $id) throw new RuntimeException('DEPOSIT_BLOCK_BATCH_FAILED');
                    $blocks[$id] = $body;
                }
                ksort($blocks);
                if ($this->round) $this->cache[$key] = $blocks;
                return $blocks;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'DEPOSIT_SCAN_BUDGET') throw $e;
                $this->failed[$chain][$url] = true;
            }
        }
        if ($chain !== 'xlayer' && config('deposits.explorer.proxy_fallback')) {
            $blocks = [];
            for ($n = $from; $n <= $to; $n++) {
                $body = $this->rpc($chain, 'eth_getBlockByNumber', ['0x' . dechex($n), true]);
                if (!is_array($body) || !is_array($body['transactions'] ?? null) || ChainAmount::integer($body['number'] ?? '') !== (string) $n) throw new RuntimeException('DEPOSIT_BLOCK_BATCH_FAILED');
                $blocks[$n] = $body;
            }
            if ($this->round) $this->cache[$key] = $blocks;
            return $blocks;
        }
        throw new RuntimeException('DEPOSIT_BLOCK_BATCH_FAILED');
    }
    public function height(string $chain): int
    {
        try {
            if (ChainAmount::integer((string) $this->rpc($chain, 'eth_chainId')) !== (string) config('deposits.evm.' . $chain . '.chain_id')) throw new RuntimeException('DEPOSIT_WRONG_CHAIN');
            if (config('deposits.evm.' . $chain . '.finality') === 'finalized') {
                $block = $this->rpc($chain, 'eth_getBlockByNumber', ['finalized', false]);
                return (int) ChainAmount::integer($block['number'] ?? '');
            }
            return (int) ChainAmount::integer((string) $this->rpc($chain, 'eth_blockNumber'));
        } catch (RuntimeException $e) {
            if (config('deposits.evm.' . $chain . '.finality') || !config('deposits.explorer.proxy_fallback') || !in_array($e->getMessage(), ['DEPOSIT_RPC_FAILED', 'DEPOSIT_RPC_MISSING', 'DEPOSIT_WRONG_CHAIN'], true)) throw $e;
            $this->charge('etherscan_head');
            $explorer = app(EvmExplorerClient::class);
            $explorer->setDeadline($this->deadline);
            return $explorer->height($chain);
        }
    }
    public function validateToken(DepositChannel $c): void
    {
        $this->height($c->chain);
        if ($c->kind !== 'token') {
            return;
        }
        if (in_array($this->rpc($c->chain, 'eth_getCode', [$c->contract, 'latest']), ['0x', '0x0', null], true)) {
            throw new RuntimeException('DEPOSIT_CONTRACT_HAS_NO_CODE');
        }
        $raw = $this->rpc($c->chain, 'eth_call', [['to' => $c->contract, 'data' => '0x313ce567'], 'latest']);
        if (ChainAmount::integer((string) $raw) !== (string) $c->decimals) {
            throw new RuntimeException('DEPOSIT_DECIMALS_MISMATCH');
        }
    }
    public function verify(DepositChannel $c, string $hash, string $address, int $head): array
    {
        if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $hash) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            throw new RuntimeException('DEPOSIT_INVALID_ID');
        }
        $receipt = $this->rpc($c->chain, 'eth_getTransactionReceipt', [$hash]);
        if (!is_array($receipt) || strtolower($receipt['transactionHash'] ?? '') !== strtolower($hash) || ($receipt['status'] ?? '') !== '0x1') {
            throw new RuntimeException('DEPOSIT_RECEIPT_NOT_SUCCESSFUL');
        }
        $block = (int) ChainAmount::integer($receipt['blockNumber'] ?? '');
        $confirms = $head - $block + 1;
        if ($confirms < $c->confirmations) {
            throw new RuntimeException('DEPOSIT_AWAITING_CONFIRMATIONS');
        }
        $canonical = $this->rpc($c->chain, 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        if (!is_array($canonical) || !isset($canonical['hash']) || strtolower($canonical['hash']) !== strtolower($receipt['blockHash'] ?? '')) {
            throw new RuntimeException('DEPOSIT_NONCANONICAL_BLOCK');
        }
        $base = ['chain' => $c->chain, 'txn' => strtolower($hash), 'address' => $address, 'block' => $block, 'block_hash' => $canonical['hash'], 'timestamp' => (int) ChainAmount::integer($canonical['timestamp'] ?? '') * 1000, 'confirmations' => $confirms, 'source' => 'evm-mainnet-receipt', 'verified_at' => now()->toIso8601String()];
        if ($c->kind === 'native') {
            $tx = $this->rpc($c->chain, 'eth_getTransactionByHash', [$hash]);
            if (!is_array($tx) || strtolower($tx['hash'] ?? '') !== strtolower($hash) || strtolower($tx['blockHash'] ?? '') !== strtolower($canonical['hash']) || strtolower($tx['to'] ?? '') !== strtolower($address)) {
                throw new RuntimeException('DEPOSIT_NATIVE_TRANSFER_MISMATCH');
            }
            return [$base + ['event_index' => 'native', 'contract' => null, 'sender' => $tx['from'], 'raw_amount' => ChainAmount::integer($tx['value'] ?? '')]];
        }
        $proofs = [];
        $seen = [];
        foreach ($receipt['logs'] ?? [] as $log) {
            $topics = $log['topics'] ?? [];
            if (strtolower($log['address'] ?? '') !== strtolower($c->contract) || count($topics) !== 3 || strtolower($topics[0]) !== '0x' . ChainAmount::TRANSFER) {
                continue;
            }
            if (!preg_match('/^0x0{24}[0-9a-fA-F]{40}$/', $topics[1]) || !preg_match('/^0x0{24}[0-9a-fA-F]{40}$/', $topics[2]) || strtolower('0x' . substr($topics[2], 26)) !== strtolower($address)) {
                continue;
            }
            if (($log['removed'] ?? false) || strtolower($log['transactionHash'] ?? '') !== strtolower($hash) || strtolower($log['blockHash'] ?? '') !== strtolower($canonical['hash']) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['data'] ?? '')) {
                throw new RuntimeException('DEPOSIT_INVALID_TRANSFER_LOG');
            }
            $index = ChainAmount::integer($log['logIndex'] ?? '');
            if (isset($seen[$index])) {
                throw new RuntimeException('DEPOSIT_DUPLICATE_LOG_INDEX');
            }
            $seen[$index] = true;
            $raw = ChainAmount::integer($log['data']);
            if (bccomp($raw, '0', 0) === 0) {
                continue;
            }
            $proofs[] = $base + ['event_index' => $index, 'contract' => $c->contract, 'sender' => '0x' . substr($topics[1], 26), 'raw_amount' => $raw];
        }
        return $proofs;
    }
}
