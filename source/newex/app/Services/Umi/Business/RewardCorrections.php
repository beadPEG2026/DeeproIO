<?php
namespace App\Services\Umi\Business;
use Illuminate\Support\Facades\DB;
final class RewardCorrections {
    public function __construct(private Engine $engine){}
    public function pay(int $actor,array $ids,string $reason,string $key):array {
        $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids);
        if(!$ids||count($ids)>100||mb_strlen(trim($reason))<10)$this->engine->fail('reason',__('请选择最多 100 笔待补发记录并填写核对依据。'));
        return $this->engine->run(null,$actor,'reward_correction',compact('ids','reason'),$key,function($op,$rules,$ruleId)use($ids,$reason){
            $pending=DB::table('umi_pending_rewards')->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
            if($pending->count()!==count($ids))$this->engine->fail('ids',__('待补发记录不存在。'));
            foreach($pending as $p){
                if(DB::table('umi_reward_corrections')->where('pending_id',$p->id)->exists())$this->engine->fail('ids',__('所选记录已经处理，请刷新列表。'));
                $paid=$this->engine->reward($op,$p->rule_id,$p->business_date,$p->account_id,$p->source_account_id,$p->plan_id,$p->kind,$p->amount,'1',['reviewed_correction'=>true,'pending_id'=>$p->id,'evidence'=>$reason]);
                if(Amount::cmp($paid,$p->amount)!==0)$this->engine->fail('amount',__('剩余额度或账户排除状态不允许全额补发，整批未执行。'));
                DB::table('umi_reward_corrections')->insert(['pending_id'=>$p->id,'operation_id'=>$op,'paid'=>$paid,'reason'=>$reason,'created_at'=>now()]);
            }
            if(!$this->engine->ledger->audit()['ok'])throw new \LogicException(__('补发对账失败，整批回滚。'));
        });
    }
}
