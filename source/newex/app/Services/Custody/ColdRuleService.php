<?php
namespace App\Services\Custody;
use Illuminate\Support\Facades\DB;

final class ColdRuleService {
    public function save(array $data,int $actor,?int $id=null): int {
        return DB::transaction(function()use($data,$actor,$id){
            // A currency row serializes create requests for the same asset/network.
            DB::table('currencies')->where('id',$data['currency_id'])->lockForUpdate()->first();
            $row=$id?DB::table('cold_storage')->where('id',$id)->lockForUpdate()->first():null;
            if($id&&(!$row||!$row->currency_id))CustodyNetwork::fail('CUSTODY_RULE_NOT_FOUND');
            if($id&&($row->cold_storage_transaction_id||DB::table('custody_transfers')->where('rule_id',$id)->whereIn('status',CustodyService::ACTIVE)->exists()))CustodyNetwork::fail('CUSTODY_ACTIVE_TRANSFER');
            $duplicates=DB::table('cold_storage')->where('currency_id',$data['currency_id'])->where('network_id',$data['network_id']);
            if($id)$duplicates->where('id','!=',$id);if($duplicates->exists())CustodyNetwork::fail('CUSTODY_RULE_DUPLICATE');
            $asset=CustodyNetwork::asset($data['currency_id'],$data['network_id']);
            if(!empty($data['address']))CustodyNetwork::address($asset['chain'],trim($data['address']));
            $values=array_intersect_key($data,array_flip(['currency_id','network_id','address','cold_min_balance_amount','cold_transfer_amount','hot_reserve','daily_limit']));
            $values['address']=trim((string)($values['address']??''));
            $values+=['status'=>false,'updated_by'=>$actor,'approved_by'=>null,'approved_at'=>null,'updated_at'=>now()];
            if($id)DB::table('cold_storage')->where('id',$id)->update($values);else $id=DB::table('cold_storage')->insertGetId($values+['created_at'=>now()]);
            app(CustodyService::class)->audit('rule.saved',['rule_id'=>$id,'before'=>$row,'after'=>$values],null,$actor);
            return $id;
        });
    }
    public function approve(int $id,int $actor): void {
        DB::transaction(function()use($id,$actor){
            $r=DB::table('cold_storage')->where('id',$id)->lockForUpdate()->first();if(!$r||!$r->currency_id)CustodyNetwork::fail('CUSTODY_RULE_NOT_FOUND');
            if((int)$r->updated_by===$actor)CustodyNetwork::fail('CUSTODY_INDEPENDENT_APPROVAL_REQUIRED');
            if(!trim((string)$r->address))CustodyNetwork::fail('CUSTODY_ADDRESS_REQUIRED');
            foreach(['cold_transfer_amount','cold_min_balance_amount','daily_limit']as$f)if(bccomp((string)$r->$f,'0',18)<=0)CustodyNetwork::fail('CUSTODY_INVALID_AMOUNT');
            $a=CustodyNetwork::asset($r->currency_id,$r->network_id);app(CustodyService::class)->network($a['chain']);
            app(CustodyBridge::class)->call($a['chain'],'validate',['sender'=>app(CustodyService::class)->hotSender($a['chain']),'destination'=>$r->address,'contract'=>$a['contract']]);
            DB::table('cold_storage')->where('id',$id)->update(['approved_by'=>$actor,'approved_at'=>now(),'status'=>true,'updated_at'=>now()]);
            app(CustodyService::class)->audit('rule.approved',['rule_id'=>$id],null,$actor);
        });
    }
    public function pause(int $id,int $actor):void {
        DB::transaction(function()use($id,$actor){
            DB::table('cold_storage')->where('id',$id)->where('currency_id','>',0)->update(['status'=>false,'updated_at'=>now()]);
            app(CustodyService::class)->audit('rule.paused',['rule_id'=>$id],null,$actor);
        });
    }
    public function remove(int $id,int $actor):void {
        DB::transaction(function()use($id,$actor){
            $r=DB::table('cold_storage')->where('id',$id)->lockForUpdate()->first();
            if(!$r||!$r->currency_id||$r->cold_storage_transaction_id||DB::table('custody_transfers')->where('rule_id',$id)->exists())CustodyNetwork::fail('CUSTODY_RULE_RETAIN_HISTORY');
            DB::table('cold_storage')->where('id',$id)->delete();app(CustodyService::class)->audit('rule.deleted',['rule'=>$r],null,$actor);
        });
    }
}
