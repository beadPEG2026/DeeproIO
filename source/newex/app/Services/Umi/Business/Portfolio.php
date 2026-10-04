<?php
namespace App\Services\Umi\Business;
use Illuminate\Support\Facades\DB;

final class Portfolio
{
    public function __construct(private Engine $engine){}
    public function state():array {
        $s=DB::table('umi_business_state')->find(1);$r=$this->engine->rules->current();
        return ['date'=>$s->business_date,'next_date'=>\Carbon\CarbonImmutable::parse($s->business_date)->addDay()->toDateString(),'paused'=>$s->paused,'rule_id'=>$r['id'],'rules'=>$r['rules'],'local_only'=>$this->engine->rules->writable()&&!$this->engine->rules->live(),'funded_live'=>$this->engine->rules->live(),'read_only'=>!$this->engine->rules->writable()];
    }
    public function show(?object $a,int $page=1):?array {
        if(!$a)return null;
        $balances=[];foreach(Ledger::POCKETS as $p)$balances[$p]=Amount::display($this->engine->ledger->balance($a->id,$p,$p==='points'?'POINTS':'UMI'));
        $balances['usdt']=Amount::display($this->engine->ledger->balance($a->id,'main','USDT'));
        $level=0;foreach($this->engine->rules->current()['rules']['vip_thresholds'] as $i=>$min)if(Amount::cmp($balances['vip'],$min)>=0)$level=$i+1;
        $rows=$this->engine->team->rows();$team=[];$direct=0;
        foreach($rows as $id=>$child){$ancestors=$this->engine->team->ancestors($id,$rows);$depth=array_search($a->id,$ancestors,true);if($depth===false)continue;if($depth===0)$direct++;$team[]=['code'=>$child->code,'parent'=>$rows[$child->parent_id]->code??null,'depth'=>$depth+1,'level'=>$child->level,'personal'=>$child->personal,'team'=>$child->team];}
        $rewards=DB::table('umi_business_rewards')->where('account_id',$a->id)->orderByDesc('id')->paginate(20,['*'],'page',$page)->withQueryString();
        $entries=DB::table('umi_business_entries')->where('bucket','like','account:'.$a->id.':%')->orderByDesc('id')->limit(40)->get();
        $total=Amount::sum(array_map(fn($p)=>$balances[$p],['main','linear','team','referral','treasure','reserve','vip','unstaking']));
        $schedule=app(ReleaseSchedule::class);$effective=$schedule->effectiveDay();
        $opening=DB::table('umi_continuity_openings')->where('account_id',$a->id)->first();
        $release=$opening?$schedule->resolve('continuity',$opening,$effective):null;
        return ['release_effective_on'=>$effective,'release'=>$release,'release_history'=>DB::table('umi_release_revisions')->where('account_id',$a->id)->orderByDesc('id')->limit(50)->get(),'level_history'=>DB::table('umi_level_history')->where('account_id',$a->id)->orderByDesc('id')->limit(50)->get(),'opening'=>DB::table('umi_continuity_openings')->where('account_id',$a->id)->first(),'account'=>$a,'parent'=>$rows[$a->parent_id??0]->code??null,'balances'=>$balances,'total_umi'=>Amount::display($total),'remaining'=>Amount::display(Amount::sub($a->quota_total,$a->quota_used)),'vip_level'=>$level,'plans'=>DB::table('umi_business_plans')->where('account_id',$a->id)->orderByDesc('id')->get()->map(function($p)use($schedule,$effective){$p->release=$schedule->resolve('plan',$p,$effective);return $p;}),'rewards'=>$rewards,'entries'=>$entries,'team'=>$team,'direct'=>$direct,'unstakes'=>DB::table('umi_business_unstakes')->where('account_id',$a->id)->orderByDesc('id')->get()];
    }
    private function accountList(string $search, int $page, array $filters=[]) {
        return DB::table('umi_business_accounts as a')->leftJoin('umi_business_accounts as p','p.id','=','a.parent_id')
            ->select('a.*','p.code as parent_code')
            ->when($search !== '',function($q)use($search){$q->where(function($q)use($search){$q->where('a.code','like','%'.$search.'%');if(ctype_digit($search))$q->orWhere('a.id',(int)$search)->orWhere('a.user_id',(int)$search);});})
            ->when(isset($filters['level']) && $filters['level']!=='',fn($q)=>$q->where('a.level',(int)$filters['level']))
            ->when(($filters['binding']??'')==='bound',fn($q)=>$q->whereNotNull('a.user_id'))
            ->when(($filters['binding']??'')==='unbound',fn($q)=>$q->whereNull('a.user_id'))
            ->when(in_array($filters['rewards']??'', ['included','excluded'],true),fn($q)=>$q->where('a.reward_excluded',$filters['rewards']==='excluded'))
            ->orderBy('a.id')->paginate(30,['*'],'accounts_page',$page)->withQueryString();
    }
    public function admin(int $selected=0,int $page=1,string $search='',int $accountPage=1,array $filters=[]):array {
        return DB::transaction(function()use($selected,$page,$search,$accountPage,$filters){
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            return ['heartbeat'=>\Illuminate\Support\Facades\Cache::get('deepro.health.umi-settlement'),'pendingRewards'=>DB::table('umi_pending_rewards as p')->leftJoin('umi_reward_corrections as c','c.pending_id','=','p.id')->whereNull('c.id')->orderBy('p.id')->limit(100)->get(['p.*']),'pools'=>DB::table('umi_business_balances')->whereIn('bucket',['system:distribution','system:inventory'])->get(),'settlementError'=>\Illuminate\Support\Facades\Cache::get('deepro.umi.settlement_error'),'state'=>$this->state(),'accounts'=>$this->accountList($search,$accountPage,$filters),'accountTotal'=>DB::table('umi_business_accounts')->count(),'accountSearch'=>$search,'accountFilters'=>$filters,'portfolio'=>$selected?$this->show($this->engine->account($selected),$page):null,'audit'=>$this->engine->ledger->audit(),'days'=>DB::table('umi_business_days')->orderByDesc('day')->limit(20)->get()->map(function($d){$d->summary=json_decode($d->summary,true);return $d;}),'versions'=>DB::table('umi_business_rules')->orderByDesc('id')->limit(20)->get()->map(function($r){$r->rules=json_decode($r->rules,true);return $r;}),'operations'=>DB::table('umi_business_operations')->orderByDesc('created_at')->limit(30)->get()];
        });
    }
}
