<?php
namespace App\Services\Umi;

use App\Models\Umi\LegacyAccount;

/** Derived views only: no re-parenting, inferred roots, or new reward levels. */
final class LegacyRelations
{
    public function forAccount(LegacyAccount $account,int $page=1): array
    {
        $rows=LegacyAccount::orderBy('legacy_id')->get(['legacy_id','parent_legacy_id','level']);
        $parents=[];$levels=[];$children=[];
        foreach($rows as $row){$id=(int)$row->legacy_id;$parents[$id]=$row->parent_legacy_id?(int)$row->parent_legacy_id:null;$levels[$id]=$row->level;if($parents[$id])$children[$parents[$id]][]=$id;}
        $id=(int)$account->legacy_id;$ancestors=[];$seen=[$id=>true];$next=$parents[$id]??null;$cycle=false;
        while($next){
            if(isset($seen[$next])){$cycle=true;break;}$seen[$next]=true;
            $known=array_key_exists($next,$parents);$ancestors[]=['id'=>$next,'in_scope'=>$known];
            if(!$known)break;$next=$parents[$next];
        }
        $queue=[[$id,0]];$seen=[$id=>true];$members=[];
        for($i=0;$i<count($queue);$i++){
            [$parent,$depth]=$queue[$i];
            foreach($children[$parent]??[] as $child){
                if(isset($seen[$child])){$cycle=true;continue;}$seen[$child]=true;
                $members[]=['id'=>$child,'parent_id'=>$parent,'depth'=>$depth+1,'level'=>$levels[$child]];$queue[]=[$child,$depth+1];
            }
        }
        $page=max(1,$page);$slice=array_slice($members,($page-1)*20,20);
        $details=LegacyAccount::whereIn('legacy_id',array_column($slice,'id'))->get()->keyBy('legacy_id');
        foreach($slice as &$member)$member['nickname']=$details[$member['id']]->identity['nickname']??'';unset($member);
        return ['ancestors'=>$ancestors,'rows'=>$slice,'page'=>$page,'last_page'=>max(1,(int)ceil(count($members)/20)),
            'known_direct'=>count($children[$id]??[]),'known_descendants'=>count($members),'max_depth'=>$members?max(array_column($members,'depth')):0,
            'cycle_detected'=>$cycle,'scope'=>'已保存资料范围','rewards_recalculated'=>false];
    }
}
