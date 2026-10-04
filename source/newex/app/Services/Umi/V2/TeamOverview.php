<?php
namespace App\Services\Umi\V2;
use App\Domain\Umi\V2\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Read-only projection of the same active-cycle basis used by daily rank settlement. */
final class TeamOverview
{
    public function inspect(int $memberId): array
    {
        $members=DB::table('umi_v2_members')->get()->keyBy('id');
        if (!$members->has($memberId)) throw new DomainException('UMI 成员不存在。');
        $children=[]; $parents=[]; $personal=[];
        foreach (DB::table('umi_v2_sponsor_edges')->get() as $e) {
            $children[$e->parent_member_id][]=(int)$e->child_member_id;
            $parents[$e->child_member_id]=(int)$e->parent_member_id;
        }
        foreach (DB::table('umi_v2_cycles as c')->join('umi_v2_live_intents as i','i.cycle_id','=','c.id')
            ->where('c.status','active')->where('c.starts_on','<=',now(config('umi-v2.timezone'))->toDateString())->get(['c.member_id','c.principal_usd']) as $c)
            $personal[$c->member_id]=Decimal::add($personal[$c->member_id]??'0',(string)$c->principal_usd);
        $rows=[]; $walk=function(int $id,int $depth,array $path)use(&$walk,&$rows,$members,$children,$personal):string {
            if (in_array($id,$path,true)) throw new DomainException('团队关系存在循环，需核对原关系。');
            $path[]=$id; $m=$members->get($id);
            if (!$m) throw new DomainException('团队关系指向缺失成员。');
            $eligible=Decimal::cmp($personal[$id]??'0','100')>=0;
            $rows[]=['id'=>$id,'member_code'=>$m->member_code,'level'=>$m->level,'status'=>$m->status,
                'depth'=>$depth,'effective_principal_usdt'=>$eligible?$personal[$id]:'0','created_at'=>$m->joined_at];
            $sum=$eligible?$personal[$id]:'0';
            foreach ($children[$id]??[] as $child) $sum=Decimal::add($sum,$walk($child,$depth+1,$path));
            return $sum;
        };
        $team='0'; $largest='0'; $branches=[];
        foreach ($children[$memberId]??[] as $child) {
            $volume=$walk($child,1,[$memberId]); $team=Decimal::add($team,$volume);
            if (Decimal::cmp($volume,$largest)>0) $largest=$volume;
            $branches[]=['member_id'=>$child,'member_code'=>$members[$child]->member_code,'effective_principal_usdt'=>$volume];
        }
        $small=Decimal::sub($team,$largest); $rank=0;
        if (Decimal::cmp($personal[$memberId]??'0','100')>=0)
            foreach (FundedSettlement::LEVELS as $level=>$minimum) if (Decimal::cmp($small,$minimum)>=0) $rank=$level;
        $next=FundedSettlement::LEVELS[$rank+1]??null;
        return FundedTime::forDisplay(['member_id'=>$memberId,'parent_code'=>isset($parents[$memberId])?($members->get($parents[$memberId])?->member_code):null,
            'direct_count'=>count($children[$memberId]??[]),'team_count'=>count($rows),
            'personal_principal_usdt'=>$personal[$memberId]??'0','effective_team_usdt'=>$team,
            'largest_branch_usdt'=>$largest,'small_area_usdt'=>$small,'projected_level'=>$rank,
            'recorded_level'=>$members[$memberId]->level,'next_level'=>$next?$rank+1:null,
            'next_level_gap_usdt'=>$next && Decimal::cmp($next,$small)>0?Decimal::sub($next,$small):'0',
            'personal_qualification_met'=>Decimal::cmp($personal[$memberId]??'0','100')>=0,
            'basis'=>'有效参与轮次的锁定 USDT 价值；小区为团队减最大分支。正式等级在日结时更新。',
            'branches'=>$branches,'rows'=>$rows]);
    }
}
