<?php

namespace App\Services\Withdrawal;

use App\Models\Withdrawal\Withdrawal;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Independent, read-only receipt verification. Never accepts client-supplied proof. */
class VerifiedChainReceipt
{
    private const TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    private const NETWORKS = [
        'xlayer' => ['xlayer', 196, null, 64], 'xlayer20' => ['xlayer', 196, 'xlayer_contract', 64],
        'eth' => ['ethereum', 1, null, 12], 'erc20' => ['ethereum', 1, 'contract', 12],
        'bnb' => ['bnb', 56, null, 15], 'bep20' => ['bnb', 56, 'bep_contract', 15],
        'matic' => ['polygon', 137, null, 128], 'matic20' => ['polygon', 137, 'matic_contract', 128],
    ];

    public function verify(Withdrawal $withdrawal, string $hash): array
    {
        $network = self::NETWORKS[$withdrawal->network->slug ?? ''] ?? null;
        if (!$network) $this->fail('This network requires an independently verified receipt adapter before manual completion.');
        [$chain, $chainId, $contractField, $minimum] = $network;
        if (!$contractField && !in_array(strtoupper($withdrawal->currency->symbol), ['ethereum' => ['ETH'], 'bnb' => ['BNB'], 'polygon' => ['MATIC','POL'], 'xlayer' => ['OKB']][$chain], true)) $this->fail('The currency does not match the native network.');
        if (!preg_match('/^0x[0-9a-fA-F]{64}$/D', $hash)) $this->fail('Invalid transaction hash.');
        $hash = strtolower($hash);
        $url = (string) config('admin-controls.receipt_rpc.' . $chain);
        $sender = strtolower(trim((string) setting($chain . '.wallet')));
        if (!preg_match('/^0x[0-9a-f]{40}$/D', $sender) || !$url) $this->fail('Configure the platform sender and verification node first.');
        try {
            if ((int) self::decimal($this->rpc($url, 'eth_chainId', [])) !== $chainId) $this->fail('Verification node returned the wrong chain.');
            $receipt = $this->rpc($url, 'eth_getTransactionReceipt', [$hash]);
            $transaction = $this->rpc($url, 'eth_getTransactionByHash', [$hash]);
            if (!is_array($receipt) || !is_array($transaction) || ($receipt['status'] ?? '') !== '0x1'
                || strtolower($receipt['transactionHash'] ?? '') !== $hash || strtolower($transaction['hash'] ?? '') !== $hash
                || strtolower($transaction['from'] ?? '') !== $sender) $this->fail('Transaction is unconfirmed, failed or has the wrong sender.');
            $blockNumber = (string) ($receipt['blockNumber'] ?? '');
            $block = $this->rpc($url, 'eth_getBlockByNumber', [$blockNumber, false]);
            $latest = (int) self::decimal($this->rpc($url, 'eth_blockNumber', []));
            if ($chain === 'xlayer') {
                $finalized = $this->rpc($url, 'eth_getBlockByNumber', ['finalized', false]);
                if (!is_array($finalized) || (int) self::decimal($finalized['number'] ?? '') < (int) self::decimal($blockNumber)) $this->fail('Receipt is stale, reorganized or lacks confirmations.');
            }
            $confirms = $latest - (int) self::decimal($blockNumber) + 1;
            if (!is_array($block) || ($block['hash'] ?? null) !== ($receipt['blockHash'] ?? null)
                || ($transaction['blockHash'] ?? null) !== ($receipt['blockHash'] ?? null)
                || $confirms < max($minimum, (int) $withdrawal->currency->min_deposit_confirmation)
                || (int) self::decimal($block['timestamp'] ?? '') < $withdrawal->created_at->timestamp) $this->fail('Receipt is stale, reorganized or lacks confirmations.');
            $destination = strtolower(trim((string) $withdrawal->address));
            $expected = bcsub((string) $withdrawal->amount, (string) $withdrawal->fee, 18);
            if (bccomp($expected, '0', 18) <= 0) $this->fail('Invalid net withdrawal amount.');
            $index = 'native'; $asset = 'native'; $matches = [];
            if ($contractField) {
                $asset = strtolower(trim((string) $withdrawal->currency->{$contractField}));
                if (!preg_match('/^0x[0-9a-f]{40}$/D', $asset)) $this->fail('Token contract is not configured.');
                $decimals = (int) self::decimal($this->rpc($url, 'eth_call', [['to' => $asset, 'data' => '0x313ce567'], $blockNumber]));
                if ($decimals < 0 || $decimals > 36) $this->fail('Unsupported token precision.');
                foreach (($receipt['logs'] ?? []) as $log) {
                    $topics = $log['topics'] ?? [];
                    if (strtolower($log['address'] ?? '') !== $asset || ($topics[0] ?? '') !== self::TRANSFER || count($topics) !== 3 || ($log['removed'] ?? false)) continue;
                    if ('0x' . substr(strtolower($topics[1]), -40) !== $sender || '0x' . substr(strtolower($topics[2]), -40) !== $destination) continue;
                    if (bccomp(self::decimal($log['data'] ?? ''), bcmul($expected, bcpow('10', (string) $decimals, 0), 36), 36) === 0) $matches[] = (string) ($log['logIndex'] ?? '');
                }
                if (count($matches) !== 1 || $matches[0] === '') $this->fail('No unique transfer matches the token, address and net amount.');
                $index = $matches[0];
            } elseif (strtolower($transaction['to'] ?? '') !== $destination || bccomp(self::decimal($transaction['value'] ?? ''), bcmul($expected, bcpow('10', '18', 0), 18), 18) !== 0) {
                $this->fail('Transaction address or net amount does not match.');
            }
            return ['chain_id' => $chainId, 'txn' => $hash, 'event_index' => $index, 'asset' => $asset, 'amount' => $expected,
                'sender' => $sender, 'destination' => $destination, 'confirmations' => $confirms,
                'block_hash' => $receipt['blockHash'], 'block_number' => $blockNumber, 'verified_at' => now()->toIso8601String(),
                'claim_key' => hash('sha256', $chainId . ':' . $hash . ':' . $index)];
        } catch (ValidationException $e) { throw $e; }
        catch (\Throwable $e) { $this->fail('Chain verification is unavailable; the withdrawal remains pending.'); }
    }

    protected function rpc(string $url, string $method, array $params): mixed
    {
        $response = Http::connectTimeout(3)->timeout(8)->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params])->throw()->json();
        if (!is_array($response) || isset($response['error']) || !array_key_exists('result', $response)) $this->fail('Invalid verification response.');
        return $response['result'];
    }

    public static function decimal(mixed $hex): string
    {
        if (!is_string($hex) || !preg_match('/^0x[0-9a-fA-F]{1,64}$/D', $hex)) throw new \InvalidArgumentException('Invalid hexadecimal chain value.');
        $number = '0';
        foreach (str_split(substr($hex, 2)) as $char) $number = bcadd(bcmul($number, '16', 0), (string) hexdec($char), 0);
        return $number;
    }

    private function fail(string $message): never
    {
        $code = match ($message) {
            'This network requires an independently verified receipt adapter before manual completion.' => 'RCPT_01',
            'The currency does not match the native network.' => 'RCPT_02',
            'Invalid transaction hash.' => 'RCPT_03',
            'Configure the platform sender and verification node first.' => 'RCPT_04',
            'Verification node returned the wrong chain.' => 'RCPT_05',
            'Transaction is unconfirmed, failed or has the wrong sender.' => 'RCPT_06',
            'Receipt is stale, reorganized or lacks confirmations.' => 'RCPT_07',
            'Invalid net withdrawal amount.' => 'RCPT_08',
            'Token contract is not configured.' => 'RCPT_09',
            'Unsupported token precision.' => 'RCPT_10',
            'No unique transfer matches the token, address and net amount.' => 'RCPT_11',
            'Transaction address or net amount does not match.' => 'RCPT_12',
            'Chain verification is unavailable; the withdrawal remains pending.' => 'RCPT_13',
            'Invalid verification response.' => 'RCPT_14',
            default => 'RCPT_UNKNOWN',
        };
        throw ValidationException::withMessages(['txn' => __('Receipt verification failed. Check the network, asset, address, amount and confirmations.') . ' [' . $code . ']']);
    }
}
