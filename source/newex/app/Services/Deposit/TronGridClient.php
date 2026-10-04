<?php
namespace App\Services\Deposit;

use Illuminate\Support\Facades\{Http, Cache};
use RuntimeException;
use StephenHill\Base58;

/** Mainnet read-only APIs. A public one-hash investigation never enables the scheduler. */
class TronGridClient
{
    public function request(string $path, array $query, bool $publicRead = false): array
    {
        $key = trim((string) config('services.trongrid.key'));
        if (!$publicRead && ($key === '' || strtolower($key) === 'null')) {
            throw new RuntimeException('TRONGRID_KEY_MISSING');
        }
        $request = Http::acceptJson()->connectTimeout(5)->timeout(20);
        if (!$publicRead) $request = $request->withHeaders(['TRON-PRO-API-KEY' => $key]);
        $cacheKey='trongrid-read:'.hash('sha256',json_encode([$publicRead?'public':hash('sha256',$key),$path,$query]));
        $cacheable=in_array($path,['wallet/getnowblock','walletsolidity/getnowblock','wallet/getchainparameters','walletsolidity/gettransactionbyid','walletsolidity/gettransactioninfobyid'],true);
        if ($cacheable && is_array($cached=Cache::get($cacheKey))) return $cached;
        $budget=app(TronRpcBudget::class);
        $budget->acquire($publicRead ? 'public' : $key);
        // No immediate per-worker retry: a provider limit pauses every PHP/Node caller.
        try {
            $response = str_starts_with($path,'wallet') ? $request->post('https://api.trongrid.io/'.$path, $query) : $request->get('https://api.trongrid.io/'.$path, $query);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $budget->outcome($publicRead ? 'public' : $key,0);
            throw new RuntimeException('TRONGRID_HTTP_UNAVAILABLE');
        }
        $data=$response->json();
        $status=$response->status();
        if (is_array($data) && (int)($data['statusCode']??0)===429) $status=429;
        $providerError=is_array($data)?($data['Error']??$data['error']??''):'';
        if (!is_string($providerError)) $providerError='';
        if ($status===200 && preg_match('/frequency limit|rate limit|quota exceeded|too many requests/i',$providerError)) $status=429;
        $budget->outcome($publicRead ? 'public' : $key,$status,$response->header('Retry-After'));
        // Do not log response bodies or headers: provider errors can contain credentials.
        if ($status<200 || $status>=300) throw new RuntimeException('TRONGRID_HTTP_'.$status);
        if (!is_array($data) || isset($data['Error']) || isset($data['error']) || ($data['success'] ?? true) === false) {
            throw new RuntimeException('TRONGRID_INVALID_RESPONSE');
        }
        $ttl=0;
        if (str_ends_with($path,'getnowblock') && isset($data['block_header']['raw_data']['number'])) $ttl=3;
        if ($path==='wallet/getchainparameters' && isset($data['chainParameter'])) $ttl=60;
        // Cache only finalized, successful raw evidence. Every event is still validated
        // against its own destination/contract; a cached hash never implies credit.
        if ($path==='walletsolidity/gettransactionbyid' && ($data['txID']??null)===($query['value']??'') && ($data['ret'][0]['contractRet']??'')==='SUCCESS') $ttl=86400;
        if ($path==='walletsolidity/gettransactioninfobyid' && ($data['id']??null)===($query['value']??'') && ($data['blockNumber']??0)>0 && ($data['blockTimeStamp']??0)>0 && ($data['receipt']['result']??'SUCCESS')==='SUCCESS' && ($data['result']??'SUCCESS')==='SUCCESS') $ttl=86400;
        if ($cacheable && $ttl>0) Cache::put($cacheKey,$data,$ttl);
        return $data;
    }

    public static function hexAddress(string $address): string
    {
        $bytes = (new Base58())->decode($address);
        if (strlen($bytes) !== 25 || ord($bytes[0]) !== 65 || !hash_equals(substr(hash('sha256', hash('sha256', substr($bytes, 0, 21), true), true), 0, 4), substr($bytes, 21))) {
            throw new RuntimeException('TRON_INVALID_ADDRESS');
        }
        return bin2hex(substr($bytes, 0, 21));
    }

    public static function base58Address(string $hex): string
    {
        if (!preg_match('/^41[0-9a-fA-F]{40}$/', $hex)) throw new RuntimeException('TRON_INVALID_ADDRESS');
        $bytes = hex2bin($hex);
        return (new Base58())->encode($bytes.substr(hash('sha256', hash('sha256', $bytes, true), true), 0, 4));
    }

    public function tokenTransfers(string $hash, string $address): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('TRON_INVALID_TXID');
        $tx=$this->request('walletsolidity/gettransactionbyid',['value'=>$hash]);
        $receipt=$this->request('walletsolidity/gettransactioninfobyid',['value'=>$hash]);
        $head=$this->request('walletsolidity/getnowblock',[]);
        $height=$head['block_header']['raw_data']['number']??null;
        if(($tx['txID']??'')!==$hash || ($receipt['id']??'')!==$hash || ($tx['ret'][0]['contractRet']??'')!=='SUCCESS' || ($receipt['receipt']['result']??'')!=='SUCCESS' ||
            ($receipt['blockNumber']??0)<=0 || ($receipt['blockTimeStamp']??0)<=0 || !is_int($height) || $height<$receipt['blockNumber'])throw new RuntimeException('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');
        $to=substr(self::hexAddress($address),2);$proofs=[];
        foreach($receipt['log']??[]as$index=>$log){
            $topics=$log['topics']??[];
            if(count($topics)!==3 || strtolower($topics[0])!==ChainAmount::TRANSFER)continue;
            if(!preg_match('/^0{24}[0-9a-fA-F]{40}$/',$topics[1]) || !preg_match('/^0{24}[0-9a-fA-F]{40}$/',$topics[2]) || strtolower(substr($topics[2],24))!==$to)continue;
            if(!preg_match('/^[0-9a-fA-F]{64}$/',$log['data']??'') || !preg_match('/^[0-9a-fA-F]{40}$/',$log['address']??''))throw new RuntimeException('TRON_INVALID_TRANSFER_LOG');
            $raw=ChainAmount::integer($log['data']);if(bccomp($raw,'0',0)===0)continue;
            $proofs[]=['chain'=>'tron','txn'=>$hash,'event_index'=>(string)$index,'contract'=>self::base58Address('41'.strtolower($log['address'])),
                'address'=>$address,'sender'=>self::base58Address('41'.strtolower(substr($topics[1],24))),'raw_amount'=>$raw,'block'=>$receipt['blockNumber'],
                'timestamp'=>$receipt['blockTimeStamp'],'confirmations'=>$height-$receipt['blockNumber']+1,'source'=>'trongrid-mainnet-solidity','verified_at'=>now()->toIso8601String()];
        }
        return $proofs;
    }

    public function verify(string $hash, string $address, bool $publicRead = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) throw new RuntimeException('TRON_INVALID_TXID');
        $tx = $this->request('walletsolidity/gettransactionbyid', ['value' => $hash], $publicRead);
        $receipt = $this->request('walletsolidity/gettransactioninfobyid', ['value' => $hash], $publicRead);
        $contracts = $tx['raw_data']['contract'] ?? [];
        if (($tx['txID'] ?? '') !== $hash || ($receipt['id'] ?? '') !== $hash ||
            ($tx['ret'][0]['contractRet'] ?? '') !== 'SUCCESS' || count($contracts) !== 1 ||
            ($contracts[0]['type'] ?? '') !== 'TransferContract' ||
            ($receipt['blockNumber'] ?? 0) <= 0 || ($receipt['blockTimeStamp'] ?? 0) <= 0 ||
            (isset($receipt['result']) && $receipt['result'] !== 'SUCCESS') ||
            (isset($receipt['receipt']['result']) && $receipt['receipt']['result'] !== 'SUCCESS')) {
            throw new RuntimeException('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');
        }
        $value = $contracts[0]['parameter']['value'] ?? [];
        $sun = (string) ($value['amount'] ?? '');
        if (strtolower($value['to_address'] ?? '') !== self::hexAddress($address) ||
            !preg_match('/^[1-9][0-9]*$/', $sun) || bccomp($sun, '9223372036854775807', 0) > 0) {
            throw new RuntimeException('TRON_TRANSFER_MISMATCH');
        }
        return ['txn' => $hash, 'address' => $address, 'sender' => self::base58Address($value['owner_address'] ?? ''),
            'sun' => $sun, 'amount' => bcdiv($sun, '1000000', 6), 'block' => $receipt['blockNumber'],
            'timestamp' => $receipt['blockTimeStamp'], 'source' => 'trongrid-mainnet-solidity',
            'verified_at' => now()->toIso8601String()];
    }
}
