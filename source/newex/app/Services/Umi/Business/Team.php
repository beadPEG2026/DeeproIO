<?php
namespace App\Services\Umi\Business;
use Illuminate\Support\Facades\DB;
final class Team {
    public function rows():array{return DB::table('umi_business_accounts')->orderBy('id')->get()->keyBy('id')->all();}
    public function ancestors(int $id,array $rows):array {$seen=[$id=>true];$result=[];$parent=$rows[$id]->parent_id??null;while($parent&&isset($rows[$parent])){if(isset($seen[$parent]))throw new \LogicException('UMI relationship cycle');$seen[$parent]=true;$result[]=(int)$parent;$parent=$rows[$parent]->parent_id;}return $result;}
    public function rates(array $rules):array{return array_column($rules['ranks'],'rate','level')+[0=>'0'];}
    public function recalculate(array $rules,?string $operation=null,?int $ruleId=null,?string $day=null):void {
        $rows=$this->rows();$branches=[];$teams=[];
        foreach($rows as $id=>$a){$child=$id;foreach($this->ancestors($id,$rows) as $parent){$teams[$parent]=Amount::add($teams[$parent]??'0',$a->personal);$branches[$parent][$child]=Amount::add($branches[$parent][$child]??'0',$a->personal);$child=$parent;}}
        foreach($rows as $id=>$a){$sum=Amount::sum($branches[$id]??[]);$max='0';foreach($branches[$id]??[] as $v)$max=Amount::max($max,$v);$small=Amount::sub($sum,$max);$level=0;foreach($rules['ranks'] as $r)if(Amount::cmp($a->personal,$r['personal'])>=0&&Amount::cmp($small,$r['small'])>=0)$level=max($level,(int)$r['level']);$level=max($level,(int)$a->manual_level);
            if($operation && $level!==(int)$a->level)DB::table('umi_level_history')->insert(['account_id'=>$id,'operation_id'=>$operation,'rule_id'=>$ruleId,'business_date'=>$day,'before_level'=>$a->level,'after_level'=>$level,'manual_level'=>$a->manual_level,'personal'=>$a->personal,'team'=>$teams[$id]??'0','small_area'=>$small,'created_at'=>now()]);
            DB::table('umi_business_accounts')->where('id',$id)->update(['team'=>$teams[$id]??'0','small_area'=>$small,'level'=>$level,'updated_at'=>now()]);
        }
    }
}
