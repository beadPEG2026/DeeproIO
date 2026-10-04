<?php

namespace App\Services\Deposit;

use Illuminate\Support\Facades\{Cache, Http};
use RuntimeException;

/** Indexed discovery only. Amounts from this API must never be used for credit. */
final class EvmExplorerClient
{
    private float $deadline = PHP_FLOAT_MAX;
    public function setDeadline(float $deadline): void { $this->deadline = $deadline; }

    public function available(?string $chain = null): bool
    {
        return ($chain === null || in_array($chain, ['ethereum','bsc','polygon'], true)) && trim((string) config('deposits.explorer.key')) !== '';
    }

    private function request(string $chain, array $params): mixed
    {
        if (microtime(true) >= $this->deadline) throw new RuntimeException('DEPOSIT_SCAN_BUDGET');
        $chainIds = ['bsc' => 56, 'ethereum' => 1, 'polygon' => 137];
        if (!isset($chainIds[$chain]) || (int) config('deposits.evm.' . $chain . '.chain_id') !== $chainIds[$chain]) throw new RuntimeException('DEPOSIT_WRONG_CHAIN');
        if (!$this->available()) {
            throw new RuntimeException('DEPOSIT_EXPLORER_MISSING');
        }
        // One shared key can serve several chains/processes. Conservative historical API limit.
        $key = 'deposit-explorer:' . hash('sha256', config('deposits.explorer.key'));
        $daily = $key . ':' . now('UTC')->format('Y-m-d');
        Cache::add($daily, 0, 90000);
        if (Cache::increment($daily) > (int) config('deposits.explorer.daily_budget', 30000)) {
            throw new RuntimeException('DEPOSIT_EXPLORER_DAILY_BUDGET');
        }
        try {
            Cache::lock($key . ':rate', 5)->block(3, function () use ($key) {
                $delay = (float) Cache::get($key . ':next', 0) - microtime(true);
                if ($delay > 0) usleep((int) (min($delay, 1) * 1000000));
                Cache::put($key . ':next', microtime(true) + (float) config('deposits.explorer.interval_seconds', 0.6), 5);
            });
            $response = Http::acceptJson()->connectTimeout(3)->timeout(15)->get('https://api.etherscan.io/v2/api', $params + [
                'chainid' => (string) config('deposits.evm.' . $chain . '.chain_id'),
                'apikey' => config('deposits.explorer.key'),
            ]);
            $data = $response->json();
        } catch (\Throwable $e) {
            // Transport exceptions may contain the key-bearing URL; never propagate them.
            throw new RuntimeException('DEPOSIT_EXPLORER_UNAVAILABLE');
        }
        if (!$response->successful() || !is_array($data)) throw new RuntimeException('DEPOSIT_EXPLORER_FAILED');
        if (($params['module'] ?? '') === 'proxy') {
            $valid = ($params['action'] ?? '') === 'eth_blockNumber'
                ? is_string($data['result'] ?? null) && preg_match('/^0x[0-9a-fA-F]+$/', $data['result'])
                : is_array($data['result'] ?? null);
            if (isset($data['error']) || !$valid) throw new RuntimeException('DEPOSIT_EXPLORER_FAILED');
            return $data['result'];
        }
        if (($data['status'] ?? '') === '0' && in_array(($data['message'] ?? ''), ['No records found', 'No transactions found'], true) && ($data['result'] ?? null) === []) return [];
        if (($data['status'] ?? '') !== '1' || !is_array($data['result'] ?? null) || !array_is_list($data['result'])) {
            throw new RuntimeException('DEPOSIT_EXPLORER_FAILED');
        }
        return $data['result'];
    }

    private function pages(string $chain, array $params): array
    {
        $size = max(1, min(1000, (int) config('deposits.explorer.page_size', 1000)));
        $all = [];
        $previous = null;
        for ($page = 1; $page <= (int) config('deposits.explorer.max_pages', 10); $page++) {
            $rows = $this->request($chain, $params + ['page' => $page, 'offset' => $size]);
            $digest = hash('sha256', json_encode($rows));
            if (count($rows) > $size) throw new RuntimeException('DEPOSIT_EXPLORER_INVALID_PAGE');
            if ($rows && $previous === $digest) throw new RuntimeException('DEPOSIT_EXPLORER_REPEATED_PAGE');
            $all = array_merge($all, $rows);
            if (count($rows) < $size) return $all;
            $previous = $digest;
        }
        throw new RuntimeException('DEPOSIT_LOG_RANGE_TOO_LARGE');
    }

    /** Read-only archive fallback; chainid scopes the official multichain API request. */
    public function proxy(string $chain, string $method, array $params): array
    {
        $query = match ($method) {
            'eth_getTransactionReceipt', 'eth_getTransactionByHash' => ['txhash' => $params[0]],
            'eth_getBlockByNumber' => ['tag' => $params[0], 'boolean' => ($params[1] ?? false) ? 'true' : 'false'],
            default => throw new RuntimeException('DEPOSIT_RPC_METHOD_FORBIDDEN'),
        };
        return $this->request($chain, ['module' => 'proxy', 'action' => $method] + $query);
    }

    public function height(string $chain): int
    {
        return (int) ChainAmount::integer($this->request($chain, ['module' => 'proxy', 'action' => 'eth_blockNumber']));
    }

    public function logs(string $chain, string $contract, int $from, int $to): array
    {
        $rows = $this->pages($chain, ['module' => 'logs', 'action' => 'getLogs', 'address' => $contract,
            'fromBlock' => $from, 'toBlock' => $to, 'topic0' => '0x' . ChainAmount::TRANSFER]);
        // Etherscan encodes index zero as bare 0x on some chains. Normalize this
        // provider-specific quantity only; the canonical receipt must still match.
        foreach ($rows as &$row) if (is_array($row) && ($row['logIndex'] ?? null) === '0x') $row['logIndex'] = '0x0';
        unset($row);
        return $rows;
    }

    /** Query indexed recipient topics across contracts; filter the configured assets later. */
    public function recipientLogs(string $chain, array $addresses, int $from, int $to): array
    {
        $all = [];
        foreach (array_unique(array_map('strtolower', $addresses)) as $address) {
            if (!preg_match('/^0x[0-9a-f]{40}$/', $address)) throw new RuntimeException('DEPOSIT_INVALID_ID');
            $rows = $this->pages($chain, ['module' => 'logs', 'action' => 'getLogs',
                'fromBlock' => $from, 'toBlock' => $to,
                'topic2' => '0x' . str_repeat('0', 24) . substr($address, 2)]);
            foreach ($rows as &$row) if (is_array($row) && ($row['logIndex'] ?? null) === '0x') $row['logIndex'] = '0x0';
            unset($row);
            $all = array_merge($all, $rows);
        }
        return $all;
    }

    public function nativeTransactions(string $chain, string $address, int $from, int $to): array
    {
        $rows = $this->pages($chain, ['module' => 'account', 'action' => 'txlist', 'address' => $address,
            'startblock' => $from, 'endblock' => $to, 'sort' => 'asc']);
        $hashes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['hash'], $row['to'], $row['value'], $row['blockNumber'], $row['isError']) ||
                !preg_match('/^0x[0-9a-fA-F]{64}$/', $row['hash']) || !ctype_digit((string) $row['blockNumber']) ||
                (int) $row['blockNumber'] < $from || (int) $row['blockNumber'] > $to || !ctype_digit((string) $row['value'])) {
                throw new RuntimeException('DEPOSIT_EXPLORER_INVALID_TRANSACTION');
            }
            if (strtolower($row['to']) === strtolower($address) && $row['isError'] === '0' && bccomp($row['value'], '0', 0) > 0) $hashes[strtolower($row['hash'])] = true;
        }
        return array_keys($hashes);
    }
}
