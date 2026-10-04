<?php
namespace App\Services\Deposit;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only native SOL adapter. RPC errors must never look like an empty history. */
final class SolanaDepositClient
{
    public const GENESIS = '5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d';
    public const SYSTEM = '11111111111111111111111111111111';
    private bool $verified = false;
    private ?int $head = null;

    public function rpc(string $method, array $params = []): mixed
    {
        if (!in_array($method, ['getGenesisHash', 'getSlot', 'getSignaturesForAddress', 'getTransaction', 'getSignatureStatuses'], true)) throw new RuntimeException('SOL_RPC_METHOD_FORBIDDEN');
        try {
            $r = Http::connectTimeout(5)->timeout(20)->post(config('solana.rpc_endpoint'), ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]);
            $body = json_decode($r->body(), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { throw new RuntimeException('SOL_RPC_UNAVAILABLE'); }
        if (!$r->successful() || !is_array($body) || isset($body['error']) || !array_key_exists('result', $body)) throw new RuntimeException('SOL_RPC_INVALID_RESPONSE');
        return $body['result'];
    }
    public function ready(): int
    {
        if (!$this->verified) {
            if ($this->rpc('getGenesisHash') !== self::GENESIS) throw new RuntimeException('SOL_WRONG_CHAIN');
            $this->verified = true;
        }
        if ($this->head !== null) return $this->head;
        $slot = $this->rpc('getSlot', [['commitment'=>'finalized']]);
        if (!is_int($slot) || $slot < 1) throw new RuntimeException('SOL_INVALID_SLOT');
        return $this->head = $slot;
    }
    public function signatures(string $address, ?string $before, ?string $until): array
    {
        $this->ready();
        $options = ['commitment'=>'finalized','limit'=>100];
        if ($before) $options['before'] = $before;
        if ($until) $options['until'] = $until;
        $rows = $this->rpc('getSignaturesForAddress', [$address, $options]);
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>100) throw new RuntimeException('SOL_INVALID_SIGNATURE_INDEX');
        foreach ($rows as $row) {
            if (!is_array($row) || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,88}$/D', $row['signature']??'') || !is_int($row['slot']??null) || !array_key_exists('err',$row) || ($row['confirmationStatus']??null)!=='finalized') throw new RuntimeException('SOL_INVALID_SIGNATURE_INDEX');
        }
        return $rows;
    }
    public function transfers(string $signature, string $address, int $expectedSlot): array
    {
        $this->ready();
        $status = $this->rpc('getSignatureStatuses', [[$signature], ['searchTransactionHistory'=>true]])['value'][0] ?? null;
        if (!$status || ($status['confirmationStatus']??null)!=='finalized' || ($status['slot']??null)!==$expectedSlot || !array_key_exists('err',$status)) throw new RuntimeException('SOL_RECEIPT_NOT_FINAL');
        if ($status['err'] !== null) return [];
        $tx = $this->rpc('getTransaction', [$signature, ['encoding'=>'jsonParsed','commitment'=>'finalized','maxSupportedTransactionVersion'=>0]]);
        if (!is_array($tx) || ($tx['transaction']['signatures'][0]??null)!==$signature || ($tx['slot']??null)!==$expectedSlot || !is_array($tx['meta']??null) || !array_key_exists('err',$tx['meta']) || !is_int($tx['blockTime']??null) || !is_array($tx['transaction']['message']['instructions']??null)) throw new RuntimeException('SOL_RECEIPT_MISMATCH');
        if ($tx['meta']['err'] !== null) throw new RuntimeException('SOL_RECEIPT_MISMATCH');
        $instructions = [];
        foreach ($tx['transaction']['message']['instructions'] as $i=>$value) $instructions['outer:'.$i]=$value;
        foreach ($tx['meta']['innerInstructions']??[] as $group) {
            if (!isset($group['index']) || !is_array($group['instructions']??null)) throw new RuntimeException('SOL_INVALID_INSTRUCTIONS');
            foreach ($group['instructions'] as $i=>$value) $instructions['inner:'.$group['index'].':'.$i]=$value;
        }
        $proofs = [];
        foreach ($instructions as $index=>$instruction) {
            if (($instruction['programId']??null)!==self::SYSTEM || !in_array($instruction['parsed']['type']??null,['transfer','transferWithSeed'],true)) continue;
            $info=$instruction['parsed']['info']??[];
            if (($info['destination']??null)!==$address || ($info['source']??null)===$address) continue;
            $amount=$info['lamports']??null;
            if (!(is_int($amount)||is_string($amount)) || !preg_match('/^[0-9]{1,20}$/D',(string)$amount) || bccomp((string)$amount,'18446744073709551615',0)>0 || empty($info['source'])) throw new RuntimeException('SOL_INVALID_TRANSFER');
            if (bccomp((string)$amount,'0',0)===0) continue;
            $proofs[]=['chain'=>'solana','txn'=>$signature,'event_index'=>$index,'contract'=>null,'address'=>$address,'sender'=>$info['source'],'raw_amount'=>(string)$amount,'timestamp'=>$tx['blockTime']*1000,'block'=>$expectedSlot,'confirmations'=>1,'finality'=>'finalized'];
        }
        return $proofs;
    }
}
