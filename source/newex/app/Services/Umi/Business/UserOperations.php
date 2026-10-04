<?php
namespace App\Services\Umi\Business;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserOperations {
    public function __construct(private Engine $engine,private CustodyTransfers $custody){}

    public function execute(int $actor,array $v,string $key):array {
        return DB::transaction(function()use($actor,$v,$key){
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            $old=DB::table('umi_business_operations')->where('scope','actor:'.$actor)->where('request_key',$key)->first();
            if(!$old && isset($v['expected_rule_id']) && (int)$v['expected_rule_id']!==(int)$this->engine->rules->current()['id'])
                $this->engine->fail('expected_rule_id',__('规则已变更，请重新预览金额和手续费。'));
            $action=$v['action'];unset($v['action'],$v['expected_rule_id']);
            if($action==='claim_to_wallet')return $this->custody->claimToWallet($actor,$v['pocket']??'',$v['amount']??'',$key);
            if(in_array($action,['custody_in','custody_out'],true))return $this->custody->transfer($actor,$v['asset']??'UMI',$action==='custody_in'?'in':'out',$v['amount']??'',$key);
            if($action==='enroll')return $this->engine->enroll($actor,$v['parent']??null,$key);
            $a=$this->engine->owned($actor);abort_unless($a,403,__('请先开通 UMI 账户。'));
            return $action==='purchase'?$this->engine->purchase($a->id,$actor,$v['amount']??'',$key):$this->engine->action($a->id,$actor,$action,$v,$key);
        },3);
    }

    /** Use the same implementation and constraints as submission, then roll back every write. */
    public function preview(int $actor,array $v):array {
        $this->engine->rules->assertLocal();
        if(($v['action']??'')==='enroll')$this->engine->fail('action',__('开户不需要金额预览。'));
        DB::beginTransaction();
        try {
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            $a=$this->engine->owned($actor);abort_unless($a,403,__('请先开通 UMI 账户。'));
            $beforeEntries=DB::table('umi_business_entries')->max('id')??0;
            $beforePlans=DB::table('umi_business_plans')->max('id')??0;
            $beforeUnstakes=DB::table('umi_business_unstakes')->max('id')??0;
            $beforeWallets=DB::table('wallets')->where('user_id',$actor)->pluck('balance_in_wallet','id');
            $this->execute($actor,$v,'preview-'.Str::uuid());
            $after=$this->engine->account($a->id);$changes=[];$fees=[];$recipient=[];
            foreach(DB::table('umi_business_entries')->where('id','>',$beforeEntries)->orderBy('id')->get() as $entry){
                $name=$entry->bucket.'|'.$entry->asset;
                if(str_starts_with($entry->bucket,'account:'.$a->id.':')){
                    $changes[$name]??=['pocket'=>explode(':',$entry->bucket)[2],'asset'=>$entry->asset,'delta'=>'0','after'=>'0'];
                    $changes[$name]['delta']=Amount::add($changes[$name]['delta'],$entry->delta);$changes[$name]['after']=$entry->balance_after;
                }elseif($entry->bucket==='system:fees')$fees[$entry->asset]=Amount::add($fees[$entry->asset]??'0',$entry->delta);
                elseif(str_starts_with($entry->bucket,'account:'))$recipient[$entry->asset]=Amount::add($recipient[$entry->asset]??'0',$entry->delta);
            }
            $wallet=[];
            foreach(DB::table('wallets as w')->join('currencies as c','c.id','=','w.currency_id')->where('w.user_id',$actor)->get(['w.id','w.balance_in_wallet','c.symbol']) as $w){$delta=Amount::sub($w->balance_in_wallet,(string)($beforeWallets[$w->id]??'0'));if(Amount::cmp($delta,'0')!==0)$wallet[$w->symbol]=$delta;}
            return ['committed'=>false,'rule_id'=>$this->engine->rules->current()['id'],'changes'=>array_values($changes),
                'fees'=>$fees,'wallet_changes'=>$wallet,'recipient_changes'=>$recipient,'quota_added'=>Amount::sub($after->quota_total,$a->quota_total),
                'plans'=>DB::table('umi_business_plans')->where('id','>',$beforePlans)->where('account_id',$a->id)->get(['amount','value_usdt','multiplier','quota','daily_rate','daily_amount','starts_on']),
                'unstakes'=>DB::table('umi_business_unstakes')->where('id','>',$beforeUnstakes)->where('account_id',$a->id)->get(['amount','fee_rate','unlock_at']),
                'notice'=>'仅预览，未扣款；确认时会再次校验余额、额度与规则版本。'];
        }finally{DB::rollBack();}
    }
}
