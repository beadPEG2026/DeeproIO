<?php
namespace App\Services\Staking;
use App\Models\Staking\StakingUser;
use App\Services\Operations\History;
use Illuminate\Support\Facades\{DB,Schema};

/** Per-currency obligations, not a claim of on-chain asset backing or a subscription reserve gate. */
final class TermOperations
{
    public function query(array $f=[])
    {
        $q=StakingUser::query()->where('meta->funded_term',1);
        if(!empty($f['currency_id'])) $q->where('currency_id',$f['currency_id']);
        if(!empty($f['status'])) $q->where('status',$f['status']);
        if(!empty($f['user_id'])) $q->where('user_id',$f['user_id']);
        if(!empty($f['due_from'])) $q->where('redemption_date','>=',\Carbon\CarbonImmutable::parse($f['due_from'])->startOfDay());
        if(!empty($f['due_to'])) $q->where('redemption_date','<',\Carbon\CarbonImmutable::parse($f['due_to'])->addDay()->startOfDay());
        if(!empty($f['failed'])) $q->where('status','active')->whereIn('id',DB::table('staking_settlement_tasks')->where('status','failed')->select('stake_id'));
        return $q;
    }

    public function row(StakingUser $s): array
    {
        $m=$s->meta??[];$platform=($m['reward_funding']??'reserved')==='platform';$active=$s->status==='active';
        return ['id'=>$s->id,'user_id'=>$s->user_id,'staking_id'=>$s->staking_id,'currency_id'=>$s->currency_id,'status'=>$s->status,
            'principal'=>(string)$s->amount,'accrued_reward'=>(string)$s->reward,'term_reward'=>(string)($m['term_reward']??$m['reserved_reward']??'0'),
            'platform_reward_due'=>$active&&$platform?(string)($m['platform_reward_due']??$m['term_reward']??'0'):'0',
            'reserved_reward'=>$active&&!$platform?(string)($m['reserved_reward']??'0'):'0',
            'paid_reward'=>(string)($m['paid_reward']??'0'),'platform_reward_expense'=>(string)($m['platform_reward_expense']??'0'),
            'funding'=>$platform?'platform':'reserved','period_return_pct'=>(string)$s->apy,
            'annualized_simple_pct'=>bcdiv(bcmul((string)$s->apy,'365',18),(string)max(1,(int)$s->days),8),
            'value_date'=>$s->value_date?->toIso8601String(),'redemption_date'=>$s->redemption_date?->toIso8601String()];
    }

    public function report(array $f): array
    {
        return $this->snapshot(fn()=> $this->buildReport($f));
    }

    public function snapshot(callable $callback): mixed
    {
        return DB::transaction(function()use($callback){
            if(DB::getDriverName()==='pgsql' && DB::transactionLevel()===1) DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $callback();
        });
    }

    private function buildReport(array $f): array
    {
        $totals=[];$now=now();
        foreach($this->query($f)->orderBy('id')->cursor() as $s){
            $r=$this->row($s);$c=$r['currency_id'];
            $totals[$c]??=['currency_id'=>$c,'positions'=>0,'active_principal'=>'0','accrued_reward'=>'0','term_reward'=>'0','platform_reward_due'=>'0','reserved_reward'=>'0','paid_reward'=>'0','platform_reward_expense'=>'0','matured_payable'=>'0','due_7d_payable'=>'0','due_30d_payable'=>'0'];
            $totals[$c]['positions']++;
            foreach(['paid_reward','platform_reward_expense'] as $k) $totals[$c][$k]=bcadd($totals[$c][$k],$r[$k],24);
            if($s->status!=='active') continue;
            $totals[$c]['active_principal']=bcadd($totals[$c]['active_principal'],$r['principal'],24);
            foreach(['accrued_reward','term_reward','platform_reward_due','reserved_reward'] as $k) $totals[$c][$k]=bcadd($totals[$c][$k],$r[$k],24);
            $payable=bcadd($r['principal'],$r['term_reward'],24);
            foreach(['matured_payable'=>0,'due_7d_payable'=>7,'due_30d_payable'=>30] as $key=>$days)
                if($s->redemption_date->lte($now->copy()->addDays($days))) $totals[$c][$key]=bcadd($totals[$c][$key],$payable,24);
        }
        $page=$this->query($f)->orderBy('redemption_date')->orderBy('id')->paginate(50)->withQueryString();
        $ids=$page->getCollection()->pluck('id');$tasks=Schema::hasTable('staking_settlement_tasks')?DB::table('staking_settlement_tasks')->whereIn('stake_id',$ids)->get()->keyBy('stake_id'):collect();
        $page->setCollection($page->getCollection()->map(fn($s)=>$this->row($s)+['settlement_task'=>$tasks->get($s->id)]));
        return ['totals'=>array_values($totals),'positions'=>$page,'filters'=>$f,'currencies'=>DB::table('currencies')->whereIn('id',array_keys($totals))->get(['id','symbol']),
            'timezone'=>config('app.timezone'),'queried_at'=>$now->toIso8601String(),'scope'=>'book_obligations_not_chain_assets',
            'history'=>Schema::hasTable('operations_events')?DB::table('operations_events')->whereIn('object_type',['funded_staking_retry','funded_staking_export'])->orderByDesc('id')->limit(30)->get(['id','object_type','object_id','actor_id','action','reason','created_at']):[]];
    }

    public function settle(int $id, ?int $actor=null, ?string $reason=null): bool
    {
        try {
            $result=app(FundedTermProduct::class)->settle($id);
            if(Schema::hasTable('staking_settlement_tasks')) DB::table('staking_settlement_tasks')->where('stake_id',$id)->update(['status'=>'resolved','attempts'=>DB::raw('attempts + 1'),'error_code'=>null,'last_attempt_at'=>now(),'resolved_at'=>now(),'updated_at'=>now()]);
            if($actor!==null) History::append('funded_staking_retry',$id,'retry',['settled'=>$result],$actor,$reason);
            return $result;
        } catch(\Throwable $error) {
            if(Schema::hasTable('staking_settlement_tasks')) DB::transaction(function()use($id,$error){
                DB::table('staking_settlement_tasks')->insertOrIgnore(['stake_id'=>$id,'status'=>'failed','attempts'=>0,'last_attempt_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                DB::table('staking_settlement_tasks')->where('stake_id',$id)->update(['status'=>'failed','attempts'=>DB::raw('attempts + 1'),'error_code'=>class_basename($error),'last_attempt_at'=>now(),'resolved_at'=>null,'updated_at'=>now()]);
            });
            if($actor!==null) History::append('funded_staking_retry',$id,'failed',['error_code'=>class_basename($error)],$actor,$reason);
            throw $error;
        }
    }
}
