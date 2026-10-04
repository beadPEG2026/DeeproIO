<?php
namespace App\Services\Umi;

use App\Models\Umi\LegacyAccount;
use App\Services\Umi\Business\Amount as A;
use Illuminate\Support\Facades\{DB,Crypt};

/** Snapshot-date reconciliation only. Closing-principal inversion is NOT a forward settlement formula. */
final class LegacyShadow
{
    public function report():array
    {
        if(DB::transactionLevel()===0)return DB::transaction(function(){DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');return $this->build();});
        return $this->build();
    }
    private function build():array
    {
        $accounts=LegacyAccount::orderBy('legacy_id')->get()->keyBy('legacy_id');$profiles=[];$records=[];$csv=[];$parents=[];$errors=[];
        foreach($accounts as $id=>$a){if(app(LegacyIntegrity::class)->account($a)){$errors[]='account:'.$id;continue;}$profiles[$id]=$a->profile;$parents[$id]=$a->parent_legacy_id;}
        foreach(DB::table('umi_legacy_records')->where('source','burn__records')->get() as $r){$v=$this->decode($r);if((int)($v['status']??0)===1)$records[(int)$r->legacy_id][]=$v;}
        foreach(DB::table('umi_legacy_summaries')->get() as $r)$csv[(int)$r->legacy_id]=$this->decode($r);
        $pv=[];$tv=[];$branches=[];$linear=[];$principal=[];$teamReward=[];$paths=[];$roots=[];
        foreach($profiles as $id=>$p){
            $pv[$id]='0';foreach($records[$id]??[] as $b)if((int)$b['burn_type']===1)$pv[$id]=A::add($pv[$id],$this->n($b['burn_value_usdt']));
            $seen=[$id=>true];$at=$parents[$id];$path=[];
            while($at&&isset($profiles[$at])){if(isset($seen[$at]))throw new \RuntimeException('UMI legacy relationship cycle');$seen[$at]=true;$path[]=(int)$at;$at=$parents[$at];}$paths[$id]=$path;
            if(isset($csv[$id])){$linear[$id]=$this->n($csv[$id]['当前待释放理财收益(UMI)']);$principal[$id]=$this->n($csv[$id]['[核查]复投宝本金(UMI)']);}
            elseif(in_array($id,[365201,365679],true)){
                $roots[]=$id;$linear[$id]='0';foreach($records[$id]??[] as $b)$linear[$id]=A::add($linear[$id],A::mul($this->n($b['burn_amount']),'.008'));
                $main='0';foreach($p['balances'] as $balance)if($balance['asset_symbol']==='UMI')$main=A::add($main,$this->n($balance['available_amount']));
                $principal[$id]=A::sub(A::sub(A::sub($this->n($p['dapp_balance_umi']),$this->n($p['performance']['referral_earnings'])),$this->n($p['performance']['team_earnings'])),$main);
            }else{$linear[$id]=null;$principal[$id]=null;$errors[]='daily_fields_missing:'.$id;}
            if($linear[$id]!==null&&A::cmp($this->n($p['quota_summary']['total_quota']),$this->n($p['quota_summary']['used_quota']))<=0)$linear[$id]='0';
        }
        foreach($profiles as $source=>$p){$child=$source;$highest='0';foreach($paths[$source] as $parent){
            $tv[$parent]=A::add($tv[$parent]??'0',$pv[$source]);$branches[$parent][$child]=A::add($branches[$parent][$child]??'0',$pv[$source]);$child=$parent;
            $rank=(int)ltrim($profiles[$parent]['performance']['current_level'],'V');$rate=A::div((string)$rank,'10');$diff=A::max('0',A::sub($rate,$highest));$highest=A::max($highest,$rate);
            $eligible=A::cmp($this->n($profiles[$parent]['quota_summary']['total_quota']),$this->n($profiles[$parent]['quota_summary']['used_quota']))>0;
            if($eligible&&$linear[$source]!==null)$teamReward[$parent]=A::add($teamReward[$parent]??'0',A::mul($linear[$source],$diff));
        }}
        $rows=[];$counts=['personal'=>0,'team'=>0,'small_area'=>0,'rank'=>0,'today'=>0];
        foreach($profiles as $id=>$p){
            $largest='0';foreach($branches[$id]??[] as $v)$largest=A::max($largest,$v);$small=A::sub($tv[$id]??'0',$largest);$rank=0;
            foreach(config('umi-business.defaults.ranks') as $r)if(A::cmp($pv[$id],$r['personal'])>=0&&A::cmp($small,$r['small'])>=0)$rank=$r['level'];
            $today=$linear[$id]!==null?A::div(A::add(A::add($linear[$id],$teamReward[$id]??'0'),A::mul($principal[$id],'.001')),'1.001'):null;
            $checks=['personal'=>$this->matches($pv[$id],$p['performance']['personal_performance']),'team'=>$this->matches($tv[$id]??'0',$p['performance']['team_performance']),'small_area'=>$this->matches($small,$p['performance']['small_area_performance']),'rank'=>$rank===(int)ltrim($p['performance']['current_level'],'V'),'today'=>$today!==null&&$this->matches($today,$p['quota_summary']['today_released'])];foreach($checks as $k=>$v)if($v)$counts[$k]++;
            $rows[]=['uid'=>$id,'parent'=>$parents[$id],'personal'=>$pv[$id],'team'=>$tv[$id]??'0','small_area'=>$small,'rank'=>$rank,'linear'=>$linear[$id],'team_reward'=>$teamReward[$id]??'0','actual_today'=>$p['quota_summary']['today_released'],'candidate_today'=>$today,'delta'=>$today===null?null:A::sub($this->n($p['quota_summary']['today_released']),$today),'checks'=>$checks,'root_candidate'=>in_array($id,$roots,true)];
        }
        return ['snapshot_date'=>'2026-09-16','generated_at'=>now()->toIso8601String(),'accounts'=>count($profiles),'matched'=>$counts,'linear_total'=>A::sum(array_filter($linear,fn($x)=>$x!==null)),'team_total'=>A::sum($teamReward),'rows'=>$rows,'errors'=>$errors,'read_only'=>true,'credited'=>false,'root_candidates'=>$roots,'note'=>'当日结余反推公式 (线性 + 团队 + 收盘宝本金 × 0.001) / 1.001，仅校验该快照。两个根账户缺少 CSV，相关日量和本金是候选；不是未来结算或旧计划自动重启依据。'];
    }
    private function decode(object $r):array {$raw=Crypt::decryptString($r->payload);if(!hash_equals($r->source_hash,hash('sha256',$raw)))throw new \RuntimeException('UMI source hash mismatch');return json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
    private function n(mixed $v):string {if(!is_string($v)&&!is_int($v))throw new \RuntimeException('UMI missing/nondecimal source field');$s=(string)$v;if(!preg_match('/^-?\d+(?:\.\d+)?$/D',$s))throw new \RuntimeException('UMI invalid source decimal');return $s;}
    private function matches(string $a,mixed $b):bool{return A::cmp(ltrim(A::sub($a,$this->n($b)),'-'),'0.000000000001')<=0;}
}
