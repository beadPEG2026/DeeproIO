<?php
namespace App\Services\Custody;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CustodyBridge {
    public function call(string $chain,string $action,array $payload=[]): array {
        if (!in_array($action,['prepare','broadcast','receipt','balance','validate','estimate'],true)) throw new RuntimeException('CUSTODY_ACTION_INVALID');
        if($chain==='bitcoin')return app(BitcoinCustody::class)->call($action,$payload);
        $url=CustodyNetwork::bridge($chain);
        if (!$url) throw new RuntimeException('CUSTODY_BRIDGE_UNCONFIGURED');
        $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stamp=(string)time(); $signature=hash_hmac('sha256',$stamp.'.'.$action.'.'.$json,(string)config('app.key'));
        // TRON preparation includes several paced reads; allow the server's 60s window.
        $timeout=$chain==='tron' && in_array($action,['prepare','estimate'],true)?65:18;
        $r=Http::connectTimeout(3)->timeout($timeout)->withHeaders(['X-Custody-Time'=>$stamp,'X-Custody-Signature'=>$signature])->withBody($json,'application/json')->post($url.'/custody/'.$action);
        $body=$r->json();
        if (!$r->successful() || !is_array($body) || ($body['success']??false)!==true) {
            $code=$body['code']??'CUSTODY_BRIDGE_UNAVAILABLE';
            throw new RuntimeException(preg_match('/^[A-Z_0-9]{3,100}$/D',(string)$code)?$code:'CUSTODY_BRIDGE_UNAVAILABLE');
        }
        return $body;
    }
}
