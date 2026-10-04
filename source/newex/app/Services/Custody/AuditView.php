<?php
namespace App\Services\Custody;
final class AuditView
{
    private const FIELDS = ['wallet_id','name','created','reference','from','to','revision','max_fee_rate','auto_cold','chain','before','after','rule','rule_id','user_id','enabled','auto_sweep','auto_sweep_scope','max_fee','max_fee_before','native_max_fee','daily_gas_limit','confirmations','currency_id','network_id','address','status','cold_min_balance_amount','cold_transfer_amount','hot_reserve','daily_limit','purpose','txn','state','withdrawal_refunded','updated_by','updated_at'];
    public function detail(?string $json): array
    {
        if (!$json || strlen($json)>65536) return [];
        return $this->filter(json_decode($json,true) ?: []);
    }
    private function filter($data): array
    {
        if (!is_array($data)) return [];
        $out=[];
        foreach (self::FIELDS as $key) if(array_key_exists($key,$data)) {
            $value=$data[$key];
            if (is_array($value)) $out[$key]=$this->filter($value);
            elseif (is_scalar($value) || $value===null) $out[$key]=is_string($value)?mb_substr($value,0,500):$value;
        }
        return $out;
    }
}
