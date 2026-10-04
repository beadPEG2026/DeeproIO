<?php
namespace App\Services\Custody;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustodyNetwork {
    public static function feeCap(object $network, ?string $contract = null): string {
        $cap = (string) (!$contract && isset($network->native_max_fee) ? $network->native_max_fee : $network->max_fee);
        if (!is_numeric($cap) || bccomp($cap, '0', 18) <= 0) self::fail('CUSTODY_FEE_LIMIT');
        return $cap;
    }

    public const MAP = [
        'btc'=>['bitcoin',null,'BTC'],
        'eth'=>['ethereum',null,'ETH'], 'erc20'=>['ethereum','contract',null],
        'bnb'=>['bnb',null,'BNB'], 'bep20'=>['bnb','bep_contract',null],
        'matic'=>['polygon',null,'MATIC'], 'matic20'=>['polygon','matic_contract',null],
        'xlayer' => ['xlayer',null,'OKB'], 'xlayer20' => ['xlayer','xlayer_contract',null],
        'trx'=>['tron',null,'TRX'], 'trc20'=>['tron','trc_contract',null],
        'sol'=>['solana',null,'SOL'], 'solspl'=>['solana','sol_contract',null], 'ton'=>['ton',null,'TON'],
    ];
    public static function asset(int $currency, int $network): array {
        $c=DB::table('currencies')->where('id',$currency)->whereNull('deleted_at')->first();
        $n=DB::table('networks')->where('id',$network)->first(); $m=self::MAP[$n->slug??'']??null;
        if (!$c || !$n || !$m || !DB::table('currency_networks')->where('currency_id',$currency)->where('network_id',$network)->exists()) self::fail('CUSTODY_UNSUPPORTED_ASSET');
        if (\App\Services\Market\AssetProfile::networkError($c,$n->slug)) self::fail('CUSTODY_ASSET_NETWORK_RESTRICTED');
        [$chain,$field,$symbol]=$m;
        if (!$field && !in_array(strtoupper($c->symbol),$chain==='polygon'?['MATIC','POL']:[$symbol],true)) self::fail('CUSTODY_NATIVE_MISMATCH');
        $contract=$field?trim((string)($c->$field??'')):null;
        if ($field && !$contract) self::fail('CUSTODY_CONTRACT_MISSING');
        if ($contract) self::address($chain,$contract);
        return ['chain'=>$chain,'contract'=>$contract,'currency_id'=>$currency,'network_id'=>$network,'symbol'=>$c->symbol];
    }
    public static function address(string $chain,string $address): void {
        if($chain==='bitcoin'){app(\App\Services\Wallet\BitcoinWalletRpc::class)->address($address);return;}
        $pattern=match($chain) {
            'ethereum','bnb','polygon','xlayer'=>'/^0x[0-9a-fA-F]{40}$/D',
            'tron'=>'/^T[1-9A-HJ-NP-Za-km-z]{33}$/D',
            'solana'=>'/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D',
            'ton'=>'/^(?:[EU]Q[A-Za-z0-9_-]{46}|0:[0-9a-fA-F]{64})$/D',
            default=>null,
        };
        if (!$pattern || !preg_match($pattern,$address) || preg_match('/^0x0{40}$/i',$address)) self::fail('CUSTODY_INVALID_ADDRESS');
    }
    public static function bridge(string $chain): string {
        return rtrim((string)config('app.'.($chain==='bnb'?'bsc':$chain).'_bridge'),'/');
    }
    public static function fail(string $code): never { throw ValidationException::withMessages(['custody'=>$code]); }
}
