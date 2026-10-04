<?php

namespace App\Services\Deposit;

use RuntimeException;

/** Combine identical chain/range filters; verify every candidate against its receipt later. */
final class EvmLogDiscovery
{
    public function __construct(private EvmDepositClient $client, private EvmExplorerClient $explorer, private bool $indexedBackfill = false) {}

    public function logs(string $chain, array $contracts, array $addresses, int $from, int $to): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('strtolower', $contracts))), max(1, (int) config('deposits.scan.contract_batch'))) as $tokens) {
            foreach (array_chunk(array_values(array_unique(array_map('strtolower', $addresses))), max(1, (int) config('deposits.scan.address_batch'))) as $recipients) {
                foreach ($this->range($chain, $tokens, $recipients, $from, $to) as $log) {
                    $key = strtolower($log['transactionHash']) . ':' . ChainAmount::integer($log['logIndex']);
                    if (isset($result[$key]) && $result[$key] !== $log) throw new RuntimeException('DEPOSIT_CONFLICTING_DISCOVERY');
                    $result[$key] = $log;
                }
            }
        }
        return array_values($result);
    }

    private function range(string $chain, array $contracts, array $addresses, int $from, int $to): array
    {
        $topics = array_map(fn($a) => '0x' . str_repeat('0', 24) . substr($a, 2), $addresses);
        try {
            try {
                if ($this->indexedBackfill || config('deposits.evm.' . $chain . '.logs_provider', 'rpc') === 'etherscan') {
                    $rows = $this->explorerRows($chain, $contracts, $addresses, $from, $to);
                } else {
                $rows = $this->client->rpc($chain, 'eth_getLogs', [[
                    'fromBlock' => '0x' . dechex($from), 'toBlock' => '0x' . dechex($to),
                    'address' => count($contracts) === 1 ? $contracts[0] : $contracts,
                    'topics' => ['0x' . ChainAmount::TRANSFER, null, count($topics) === 1 ? $topics[0] : $topics],
                ]]);
                if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('DEPOSIT_INVALID_LOG_INDEX');
                if (count($rows) >= (int) config('deposits.scan.log_limit')) throw new RuntimeException('DEPOSIT_LOG_RANGE_TOO_LARGE');
                }
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'DEPOSIT_RPC_FAILED' || !config('deposits.explorer.logs_fallback') || !$this->explorer->available($chain)) throw $e;
                $rows = $this->explorerRows($chain, $contracts, $addresses, $from, $to);
            }
        } catch (RuntimeException $e) {
            if (!in_array($e->getMessage(), ['DEPOSIT_LOG_RANGE_TOO_LARGE', 'DEPOSIT_RPC_FAILED'], true)) throw $e;
            if ($from < $to) {
                $mid = intdiv($from + $to, 2);
                return array_merge($this->range($chain, $contracts, $addresses, $from, $mid), $this->range($chain, $contracts, $addresses, $mid + 1, $to));
            }
            foreach (['contracts' => $contracts, 'addresses' => $addresses] as $dimension => $values) {
                if (count($values) < 2) continue;
                $half = (int) ceil(count($values) / 2);
                $all = [];
                foreach (array_chunk($values, $half) as $part) $all = array_merge($all, $this->range($chain, $dimension === 'contracts' ? $part : $contracts, $dimension === 'addresses' ? $part : $addresses, $from, $to));
                return $all;
            }
            throw $e;
        }
        $accepted = [];
        foreach ($rows as $log) {
            if (!is_array($log) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $log['address'] ?? '')) throw new RuntimeException('DEPOSIT_INVALID_LOG_INDEX');
            // Recipient indexing also returns approvals/NFTs/unlisted assets; these cannot credit.
            if (!in_array(strtolower($log['address']), $contracts, true) || strtolower($log['topics'][0] ?? '') !== '0x' . ChainAmount::TRANSFER) continue;
            if (!is_array($log) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $log['transactionHash'] ?? '') ||
                !isset($log['logIndex'], $log['blockNumber']) || !is_array($log['topics'] ?? null) ||
                count($log['topics']) !== 3 || !preg_match('/^0x0{24}[0-9a-fA-F]{40}$/', $log['topics'][2])) throw new RuntimeException('DEPOSIT_INVALID_LOG_INDEX');
            $block = (int) ChainAmount::integer($log['blockNumber']);
            ChainAmount::integer($log['logIndex']);
            if ($block < $from || $block > $to || ($log['removed'] ?? false)) throw new RuntimeException('DEPOSIT_INVALID_LOG_INDEX');
            // Explorer queries cover every recipient of the contract, so filter locally too.
            if (!in_array(strtolower($log['address'] ?? ''), $contracts, true) || strtolower($log['topics'][0]) !== '0x' . ChainAmount::TRANSFER ||
                !in_array(strtolower('0x' . substr($log['topics'][2], 26)), $addresses, true)) continue;
            $accepted[] = $log;
        }
        return $accepted;
    }
    private function explorerRows(string $chain, array $contracts, array $addresses, int $from, int $to): array
    {
        if (config('deposits.explorer.recipient_filter', false)) return $this->explorer->recipientLogs($chain, $addresses, $from, $to);
        $rows = [];
        foreach ($contracts as $contract) $rows = array_merge($rows, $this->explorer->logs($chain, $contract, $from, $to));
        return $rows;
    }

}
