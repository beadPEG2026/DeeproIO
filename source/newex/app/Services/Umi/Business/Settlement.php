<?php
namespace App\Services\Umi\Business;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Settlement
{
    public function __construct(private Engine $engine) {}

    public function preview(int $actor,string $day):array
    {
        DB::beginTransaction();
        try {
            $r=$this->advance($actor,$day,(string)\Illuminate\Support\Str::uuid());
            $rewards=DB::table('umi_business_rewards')->where('operation_id',$r['id'])->get();
            return ['day'=>$day,'total'=>Amount::sum($rewards->pluck('paid')->all()),'rewards'=>$rewards,
                'pending'=>DB::table('umi_pending_rewards')->where('operation_id',$r['id'])->get(),'committed'=>false];
        } finally {DB::rollBack();}
    }

    public function unlockDue():void
    {
        if(!$this->engine->rules->live())return;
        $clock=CarbonImmutable::now(config('umi-business.defaults.timezone'))->format('Y-m-d H:i:s');
        if(!DB::table('umi_business_unstakes')->whereNull('completed_at')->where('unlock_at','<=',$clock)->exists())return;
        $this->engine->run(null,0,'unlock_due',['clock'=>$clock],'unlock-'.str_replace([' ',':'],'-',$clock),function($op)use($clock){
            foreach(DB::table('umi_business_unstakes')->whereNull('completed_at')->where('unlock_at','<=',$clock)->orderBy('id')->lockForUpdate()->get() as $u){
                $fee=Amount::mul($u->amount,$u->fee_rate);$from=Ledger::bucket($u->account_id,'unstaking');
                $this->engine->ledger->move($op,$from,'system:fees',$fee,'UMI','VIP 解押原费率');
                $this->engine->ledger->move($op,$from,Ledger::bucket($u->account_id,'main'),Amount::sub($u->amount,$fee),'UMI','VIP 到期解押');
                DB::table('umi_business_unstakes')->where('id',$u->id)->update(['completed_at'=>$clock]);
            }
        });
    }

    /** Advance exactly one explicitly requested local business date, atomically. */
    public function advance(int $actor,string $day,string $key):array
    {
        if($this->engine->rules->live() && $day>=now(config('umi-business.defaults.timezone'))->toDateString())$this->engine->fail('day',__('仅可结算已经结束的业务日。'));
        return $this->engine->run(null,$actor,'settle',['day'=>$day],$key,function($op,$rules,$ruleId,$previous)use($day){
            $expected=CarbonImmutable::parse($previous)->addDay()->toDateString();
            if($day!==$expected)$this->engine->fail('day',__('请按顺序推进到 ').$expected.__('，不能跳日或重复发放。'));
            $this->engine->team->recalculate($rules,$op,$ruleId,$day);
            $schedule=app(ReleaseSchedule::class);
            $accounts=$this->engine->team->rows();$rates=$this->engine->team->rates($rules);$opening=[];$linear=[];$paidByKind=[];
            foreach($accounts as $id=>$a){
                if(!$this->engine->rules->live()){$opening[$id]=$this->engine->ledger->balance($id,'treasure');continue;}
                // Live operations carry their actual business date. Deposits on this
                // date start earning tomorrow; completed earlier settlements compound.
                $opening[$id]=(string)DB::table('umi_business_entries as e')
                    ->join('umi_business_operations as o','o.id','=','e.operation_id')
                    ->leftJoin('umi_business_days as d','d.operation_id','=','o.id')
                    ->leftJoin('umi_continuity_batches as cb','cb.operation_id','=','o.id')
                    ->where('e.bucket',Ledger::bucket($id,'treasure'))->where('e.asset','UMI')
                    ->where(function($q)use($day){$q->where(function($q)use($day){$q->whereNotIn('o.type',['settle','continuity'])->where('o.business_date','<',$day);})
                        ->orWhere(function($q)use($day){$q->where('o.type','continuity')->where('cb.cutoff','<',$day);})
                        ->orWhere(function($q)use($day){$q->where('o.type','settle')->where('d.day','<',$day);});})
                    ->sum('e.delta');
            }
            foreach(DB::table('umi_business_plans')->whereIn('status',['active','paused'])->where('starts_on','<=',$day)->orderBy('account_id')->orderBy('id')->get() as $p){
                $release=$schedule->resolve('plan',$p,$day);
                if($release['status']!=='active')continue;
                $left=Amount::sub($p->quota,$p->released);
                $paid=$this->engine->reward($op,$ruleId,$day,$p->account_id,$p->account_id,$p->id,'linear',$p->amount,$release['daily_rate'],['plan_rule_id'=>$p->rule_id,'snapshot_rate'=>$p->daily_rate,'release_revision'=>$release['revision_id']],$left);
                $released=Amount::add($p->released,$paid);
                $linear[$p->account_id]=Amount::add($linear[$p->account_id]??'0',$paid);
                DB::table('umi_business_plans')->where('id',$p->id)->update(['released'=>$released,'last_released_on'=>$day,'status'=>Amount::cmp($released,$p->quota)>=0?'completed':'active']);
            }
            // Snapshot daily entitlement is separate from new purchase plans. Historical
            // cumulative usage is already in the opening quota and is never reissued.
            foreach(DB::table('umi_continuity_openings')->where('status','ready')->where('cutoff','<',$day)->orderBy('account_id')->get() as $o){
                $release=$schedule->resolve('continuity',$o,$day);
                if($release['status']!=='active')continue;
                $paid=$this->engine->reward($op,$ruleId,$day,$o->account_id,$o->account_id,null,'linear',$release['daily_amount'],'1',['release_revision'=>$release['revision_id'],'legacy_snapshot'=>$o->legacy_id,'cutoff'=>$o->cutoff,'mode'=>'account_daily_entitlement']);
                $linear[$o->account_id]=Amount::add($linear[$o->account_id]??'0',$paid);
            }
            // Each source's own rank is excluded from the intermediary differential.
            foreach($linear as $source=>$amount){
                $highest='0';$path=[];
                foreach($this->engine->team->ancestors($source,$accounts) as $recipient){
                    $a=$accounts[$recipient];$rate=$rates[$a->level]??'0';$diff=Amount::max('0',Amount::sub($rate,$highest));
                    $fresh=$this->engine->account($recipient);
                    $path[]=['id'=>$recipient,'level'=>$a->level,'rate'=>$rate,'intermediary_max'=>$highest,'effective_rate'=>$diff,'remaining_quota'=>Amount::sub($fresh->quota_total,$fresh->quota_used),'reward_excluded'=>(bool)$a->reward_excluded];
                    if(Amount::cmp($diff,'0')>0)$this->engine->reward($op,$ruleId,$day,$recipient,$source,null,'team',$amount,$diff,['path'=>$path]);
                    $highest=Amount::max($highest,$rate);
                }
            }
            foreach($opening as $id=>$principal)if(Amount::cmp($principal,'0')>0)$this->engine->reward($op,$ruleId,$day,$id,$id,null,'interest',$principal,$rules['treasure_daily_rate'],['opening_principal'=>$principal,'before_auto_deposit'=>true]);
            // Peer basis is frozen before any peer awards; never recurse across levels.
            if($rules['peer_policy']==='nearest_equal_nonrecursive'){
                $base=[];
                foreach(DB::table('umi_business_rewards')->where('operation_id',$op)->get() as $reward)$base[$reward->account_id]=Amount::add($base[$reward->account_id]??'0',$reward->paid);
                foreach(DB::table('umi_business_rewards')->where('business_date',$previous)->where('kind','referral')->get() as $reward)$base[$reward->account_id]=Amount::add($base[$reward->account_id]??'0',$reward->paid);
                foreach($accounts as $source=>$a){
                    if($a->level<$rules['peer_min_level']||Amount::cmp($base[$source]??'0','0')<=0)continue;
                    foreach($this->engine->team->ancestors($source,$accounts) as $recipient)if($accounts[$recipient]->level===$a->level){
                        $this->engine->reward($op,$ruleId,$day,$recipient,$source,null,'peer',$base[$source],$rules['peer_rate'],['policy'=>'local_candidate_nearest_equal_nonrecursive','level'=>$a->level,'referral_business_date'=>$previous]);break;
                    }
                }
            }
            foreach($accounts as $id=>$a)foreach(['linear','team'] as $pocket){
                $amount=$this->engine->ledger->balance($id,$pocket);
                $this->engine->ledger->move($op,Ledger::bucket($id,$pocket),Ledger::bucket($id,'treasure'),$amount,'UMI','日结自动存入 UMI 宝');
            }
            $clock=CarbonImmutable::parse($day,$rules['timezone'])->startOfDay()->format('Y-m-d H:i:s');
            foreach(DB::table('umi_business_unstakes')->whereNull('completed_at')->where('unlock_at','<=',$clock)->orderBy('id')->get() as $u){
                $fee=Amount::mul($u->amount,$u->fee_rate);$from=Ledger::bucket($u->account_id,'unstaking');
                $this->engine->ledger->move($op,$from,'system:fees',$fee,'UMI','VIP 解押原费率');
                $this->engine->ledger->move($op,$from,Ledger::bucket($u->account_id,'main'),Amount::sub($u->amount,$fee),'UMI','VIP 到期解押');
                DB::table('umi_business_unstakes')->where('id',$u->id)->update(['completed_at'=>$clock]);
            }
            foreach(DB::table('umi_business_rewards')->where('operation_id',$op)->get() as $reward)$paidByKind[$reward->kind]=Amount::add($paidByKind[$reward->kind]??'0',$reward->paid);
            $audit=$this->engine->ledger->audit();if(!$audit['ok'])throw new \LogicException('UMI ledger audit failed; daily transaction rolled back');
            DB::table('umi_business_days')->insert(['day'=>$day,'operation_id'=>$op,'rule_id'=>$ruleId,'summary'=>json_encode(['rewards'=>$paidByKind,'accounts'=>count($accounts),'ledger_ok'=>true,'mode'=>$this->engine->rules->live()?'funded_live':'local_acceptance'],JSON_THROW_ON_ERROR),'created_at'=>now()]);
            DB::table('umi_business_state')->where('id',1)->update(['business_date'=>$day]);
        });
    }
}
