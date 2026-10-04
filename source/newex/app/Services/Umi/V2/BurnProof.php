<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use App\Services\Deposit\ChainAmount;
use App\Services\Deposit\EvmDepositClient;
use RuntimeException;

/** Rechecks the canonical BSC receipt; a custody status alone is never burn evidence. */
class BurnProof
{
    public function __construct(private readonly EvmDepositClient $rpc) {}

    public function verify(string $hash, string $sender, string $contract,
        string $amount, int $confirmations): array
    {
        if (!preg_match('/^0x[0-9a-f]{64}$/iD', $hash)) {
            throw new RuntimeException('UMI_BURN_HASH_INVALID');
        }
        $receipt = $this->rpc->rpc('bsc', 'eth_getTransactionReceipt', [$hash]);
        if (!is_array($receipt) || strtolower((string) ($receipt['transactionHash'] ?? '')) !== strtolower($hash)
            || ($receipt['status'] ?? null) !== '0x1') {
            throw new RuntimeException('UMI_BURN_RECEIPT_INVALID');
        }
        $blockNumber = (int) ChainAmount::integer((string) ($receipt['blockNumber'] ?? ''));
        $head = $this->rpc->height('bsc');
        if ($head - $blockNumber + 1 < $confirmations) {
            throw new RuntimeException('UMI_BURN_AWAITING_FINALITY');
        }
        $block = $this->rpc->rpc('bsc', 'eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        if (!is_array($block) || strtolower((string) ($block['hash'] ?? '')) !==
            strtolower((string) ($receipt['blockHash'] ?? ''))) {
            throw new RuntimeException('UMI_BURN_BLOCK_NOT_CANONICAL');
        }
        $matches = [];
        foreach ($receipt['logs'] ?? [] as $log) {
            $topics = $log['topics'] ?? [];
            if (($log['removed'] ?? false) || !is_array($topics) || count($topics) !== 3
                || strtolower((string) ($log['address'] ?? '')) !== strtolower($contract)
                || strtolower((string) $topics[0]) !== '0x' . ChainAmount::TRANSFER
                || strtolower((string) $topics[1]) !== '0x000000000000000000000000' . substr(strtolower($sender), 2)
                || strtolower((string) $topics[2]) !== '0x000000000000000000000000' . substr(FundedReadiness::DEAD, 2)
                || strtolower((string) ($log['transactionHash'] ?? '')) !== strtolower($hash)
                || strtolower((string) ($log['blockHash'] ?? '')) !== strtolower((string) $block['hash'])) {
                continue;
            }
            $value = ChainAmount::decimal(ChainAmount::integer((string) ($log['data'] ?? '')), 18);
            if (Decimal::cmp($value, $amount) === 0) {
                $matches[] = ['log_index' => (int) ChainAmount::integer((string) $log['logIndex']),
                    'amount_umi' => $value];
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('UMI_BURN_TRANSFER_NOT_UNIQUE');
        }
        return $matches[0] + [
            'chain_id' => 56, 'token_contract' => strtolower($contract),
            'tx_hash' => strtolower($hash), 'block_number' => $blockNumber,
            'block_hash' => strtolower((string) $block['hash']),
            'finality_status' => 'final', 'finalized_at' => FundedTime::database(now()),
            'receipt_sha256' => hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR)),
        ];
    }
}
