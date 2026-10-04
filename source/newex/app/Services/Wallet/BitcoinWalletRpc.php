<?php
namespace App\Services\Wallet;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Wallet RPC never uses the external read-only provider or a browser-supplied URL. */
class BitcoinWalletRpc {
    public function call(string $method,array $params=[],?string $wallet=null) {
        $allowed=['getblockchaininfo','getwalletinfo','listwallets','listwalletdir','loadwallet','createwallet','backupwallet',
            'getnewaddress','getaddressinfo','validateaddress','getbalances','listunspent','listsinceblock','gettransaction',
            'estimatesmartfee','walletcreatefundedpsbt','walletprocesspsbt','finalizepsbt','decoderawtransaction','sendrawtransaction'];
        if(!in_array($method,$allowed,true))throw new RuntimeException('BTC_RPC_METHOD_NOT_ALLOWED');
        $url=config('bitcoind.default.scheme').'://'.config('bitcoind.default.host').':'.config('bitcoind.default.port');
        if($wallet!==null)$url.='/wallet/'.rawurlencode($wallet);
        try {
            $r=Http::connectTimeout(3)->timeout(in_array($method,['backupwallet','createwallet','loadwallet'],true)?60:20)
                ->withBasicAuth((string)config('bitcoind.default.user'),(string)config('bitcoind.default.password'))
                ->post($url,['jsonrpc'=>'2.0','id'=>'deepro-wallet','method'=>$method,'params'=>$params]);
            $b=$r->json();
            if(is_array($b) && !empty($b['error']))throw new RuntimeException('BTC_RPC_ERROR_'.abs((int)($b['error']['code']??0)));
            if(!$r->successful() || !is_array($b) || !array_key_exists('result',$b))throw new RuntimeException('BTC_WALLET_RPC_UNAVAILABLE');
            return $b['result'];
        }catch(\Throwable $e){throw new RuntimeException(preg_match('/^BTC_[A-Z_0-9]+$/D',$e->getMessage())?$e->getMessage():'BTC_WALLET_RPC_UNAVAILABLE');}
    }
    public function ready(?string $wallet=null,bool $sign=false): array {
        $chain=$this->call('getblockchaininfo'); $info=$this->call('getwalletinfo',[],$wallet);
        if(($chain['chain']??null)!==config('bitcoind.management_chain','main') || ($chain['initialblockdownload']??true)
            || ($chain['verificationprogress']??0)<0.999 || !isset($info['walletname']) || ($info['scanning']??false)!==false)
            throw new RuntimeException('BTC_WALLET_NOT_READY');
        if($sign && (!(bool)($info['private_keys_enabled']??false) || (isset($info['unlocked_until'])&&$info['unlocked_until']<=time())))
            throw new RuntimeException('BTC_WALLET_CANNOT_SIGN');
        return $info;
    }
    public function address(string $address): void {
        if(($this->call('validateaddress',[$address])['isvalid']??false)!==true)throw new RuntimeException('BTC_INVALID_ADDRESS');
    }
    public static function amount($value): string {
        if(!is_numeric($value))throw new RuntimeException('BTC_INVALID_AMOUNT');
        $v=is_float($value)?number_format($value,8,'.',''):(string)$value;
        if(bccomp($v,'0',16)<0 || bccomp($v,bcadd($v,'0',8),16)!==0)throw new RuntimeException('BTC_INVALID_AMOUNT');
        return bcadd($v,'0',8);
    }
}
