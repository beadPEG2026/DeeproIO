<?php
namespace App\Services\Umi\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class Ledger {
    const POCKETS=['main','linear','team','referral','treasure','reserve','vip','unstaking','points'];
    public static function bucket(int $account,string $pocket):string {if(!in_array($pocket,self::POCKETS,true))throw new \InvalidArgumentException('Pocket');return 'account:'.$account.':'.$pocket;}
    public function balance(int $account,string $pocket,string $asset='UMI'):string{return (string)(DB::table('umi_business_balances')->where('bucket',self::bucket($account,$pocket))->where('asset',$asset)->value('amount')??'0');}
    public function move(string $op,string $from,string $to,string $amount,string $asset,string $description):void {
        if(!DB::transactionLevel())throw new \LogicException('Ledger requires a locked transaction');
        $amount=Amount::valid($amount);if(Amount::cmp($amount,'0')===0)return;
        if($from===$to||!in_array($asset,['UMI','USDT','POINTS'],true))throw new \InvalidArgumentException('Ledger transfer');
        foreach([$from,$to] as $bucket)if(!preg_match('/^(?:account:[1-9][0-9]*:(?:main|linear|team|referral|treasure|reserve|vip|unstaking|points)|system:(?:funding|custody|distribution|retired|fees|inventory|points|issuance))$/D',$bucket))throw new \InvalidArgumentException('Ledger bucket');
        if($from==='system:issuance'){
            if($asset!=='UMI')throw new \LogicException('Only UMI may be issued');
            $issued=Amount::sub('0',(string)(DB::table('umi_business_balances')->where('bucket','system:issuance')->where('asset','UMI')->value('amount')??'0'));
            if(Amount::cmp(Amount::add($issued,$amount),Continuity::ISSUE_LIMIT)>0)throw ValidationException::withMessages(['amount'=>__('超出一亿 UMI 站内发行上限。')]);
        }
        foreach([$from,$to] as $bucket)DB::table('umi_business_balances')->insertOrIgnore(['bucket'=>$bucket,'asset'=>$asset,'amount'=>'0']);
        $rows=DB::table('umi_business_balances')->whereIn('bucket',[$from,$to])->where('asset',$asset)->orderBy('bucket')->lockForUpdate()->get()->keyBy('bucket');
        if(str_starts_with($from,'account:')&&Amount::cmp($rows[$from]->amount,$amount)<0)throw ValidationException::withMessages(['amount'=>__('余额不足，请先核对可用资产或储备金。')]);
        if(app(Rules::class)->live() && in_array($from,['system:distribution','system:inventory','system:fees','system:retired'],true) && Amount::cmp($rows[$from]->amount,$amount)<0)throw ValidationException::withMessages(['amount'=>__('对应资金池余额不足，本笔操作未执行，请先补充实际资产。')]);
        foreach([[$from,Amount::sub('0',$amount)],[$to,$amount]] as [$bucket,$delta]){
            $after=Amount::add($rows[$bucket]->amount,$delta);
            DB::table('umi_business_balances')->where('bucket',$bucket)->where('asset',$asset)->update(['amount'=>$after]);
            DB::table('umi_business_entries')->insert(['operation_id'=>$op,'bucket'=>$bucket,'asset'=>$asset,'delta'=>$delta,'balance_after'=>$after,'description'=>$description,'created_at'=>now()->toIso8601String()]);
        }
    }
    public function audit():array {
        $errors=[];$balances=[];$ops=[];
        foreach(DB::table('umi_business_entries')->orderBy('id')->cursor() as $e){$key=$e->bucket.'|'.$e->asset;$balances[$key]=Amount::add($balances[$key]??'0',$e->delta);if(Amount::cmp($balances[$key],$e->balance_after)!==0)$errors[]='entry:'.$e->id;$op=$e->operation_id.'|'.$e->asset;$ops[$op]=Amount::add($ops[$op]??'0',$e->delta);}
        foreach($ops as $k=>$v)if(Amount::cmp($v,'0')!==0)$errors[]='unbalanced:'.$k;
        $cached=[];
        foreach(DB::table('umi_business_balances')->get() as $b){$key=$b->bucket.'|'.$b->asset;$cached[$key]=true;if(Amount::cmp($b->amount,$balances[$key]??'0')!==0)$errors[]='balance:'.$key;if(str_starts_with($b->bucket,'account:')&&Amount::cmp($b->amount,'0')<0)$errors[]='negative:'.$key;}
        foreach($balances as $key=>$value)if(!isset($cached[$key]))$errors[]='missing_balance:'.$key;
        $quota=[];$released=[];$awarded=[];
        foreach(DB::table('umi_continuity_openings')->get() as $o){$quota[$o->account_id]=$o->quota_used;$awarded[$o->account_id]=$o->quota_total;}
        foreach(DB::table('umi_business_rewards')->orderBy('id')->get() as $r){
            if(Amount::cmp($r->quota_before,$quota[$r->account_id]??'0')!==0||Amount::cmp($r->quota_after,Amount::add($r->quota_before,$r->paid))!==0)$errors[]='reward_quota_chain:'.$r->id;
            if(Amount::cmp($r->paid,'0')<0||Amount::cmp($r->paid,$r->expected)>0||Amount::cmp($r->expected,Amount::mul($r->base,$r->rate))!==0)$errors[]='reward_amount:'.$r->id;
            $quota[$r->account_id]=$r->quota_after;
            if($r->kind==='linear')$released[$r->plan_id]=Amount::add($released[$r->plan_id]??'0',$r->paid);
        }
        foreach(DB::table('umi_business_plans')->get() as $p){
            $awarded[$p->account_id]=Amount::add($awarded[$p->account_id]??'0',$p->quota);
            if(Amount::cmp($p->released,$released[$p->id]??'0')!==0||Amount::cmp($p->released,$p->quota)>0||Amount::cmp($p->quota,Amount::mul($p->amount,$p->multiplier))!==0||Amount::cmp($p->daily_amount,Amount::mul($p->amount,$p->daily_rate))!==0)$errors[]='plan:'.$p->id;
        }
        foreach(DB::table('umi_business_operations')->where('type','quota')->get() as $op){$v=json_decode($op->details,true,512,JSON_THROW_ON_ERROR);$id=$v['account_id'];$awarded[$id]=$v['direction']==='add'?Amount::add($awarded[$id]??'0',$v['amount']):Amount::sub($awarded[$id]??'0',$v['amount']);}
        foreach(DB::table('umi_business_accounts')->get() as $a){
            if(Amount::cmp($quota[$a->id]??'0',$a->quota_used)!==0)$errors[]='quota_used:'.$a->id;
            if(Amount::cmp($awarded[$a->id]??'0',$a->quota_total)!==0)$errors[]='quota_total:'.$a->id;
            $pending=(string)DB::table('umi_business_unstakes')->where('account_id',$a->id)->whereNull('completed_at')->sum('amount');
            if(Amount::cmp($pending,$this->balance($a->id,'unstaking'))!==0)$errors[]='unstaking:'.$a->id;
        }
        foreach(DB::table('umi_business_rules')->get() as $r){$json=json_encode(json_decode($r->rules,true,512,JSON_THROW_ON_ERROR),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(!hash_equals($r->digest,hash('sha256',$json)))$errors[]='rules_hash:'.$r->id;}
        return ['ok'=>!$errors,'errors'=>$errors,'entries'=>DB::table('umi_business_entries')->count(),'operations'=>count($ops),'mode'=>app(Rules::class)->live()?'funded_live':'local_acceptance'];
    }
}
