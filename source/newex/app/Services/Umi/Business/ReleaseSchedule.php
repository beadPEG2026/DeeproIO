<?php
namespace App\Services\Umi\Business;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Effective-dated overlays. Original plan economics and legacy snapshots remain intact. */
final class ReleaseSchedule {
    public function effectiveDay():string {
        return app(Rules::class)->live()?now(config('umi-business.defaults.timezone'))->toDateString():
            CarbonImmutable::parse(DB::table('umi_business_state')->value('business_date'))->addDay()->toDateString();
    }
    public function resolve(string $type,object $target,string $day):array {
        $id=$type==='plan'?$target->id:$target->account_id;
        $revision=DB::table('umi_release_revisions')->where('target_type',$type)->where('target_id',$id)
            ->where('effective_on','<=',$day)->orderByDesc('effective_on')->orderByDesc('id')->first();
        return ['revision_id'=>$revision?->id??0,'effective_on'=>$revision?->effective_on,
            'status'=>$revision?->status??($type==='plan'?$target->status:($target->status==='ready'?'active':'review')),
            'daily_rate'=>$revision?->daily_rate??($type==='plan'?$target->daily_rate:null),
            'daily_amount'=>$revision?->daily_amount??($type==='plan'?$target->daily_amount:$target->daily_release)];
    }
    public function revise(int $actor,array $v,string $key):array {
        $e=app(Engine::class);
        return $e->run(null,$actor,'release_revision',$v,$key,function($op)use($e,$v){
            if(mb_strlen(trim($v['reason']??''))<10)$e->fail('reason',__('请填写至少 10 个字的释放调整依据。'));
            $type=$v['target_type']??'';
            if(!in_array($type,['plan','continuity'],true))$e->fail('target_type',__('请选择新计划或历史接续。'));
            $table=$type==='plan'?'umi_business_plans':'umi_continuity_openings';$column=$type==='plan'?'id':'account_id';
            $target=DB::table($table)->where($column,(int)($v['target_id']??0))->first();abort_unless($target,404);
            if((int)$target->account_id!==(int)($v['account_id']??0))$e->fail('account_id',__('该释放计划不属于所选账户。'));
            if($type==='plan'&&($target->status==='completed'||Amount::cmp($target->released,$target->quota)>=0))$e->fail('status',__('已完成的计划不能重新释放。'));
            if($type==='continuity'&&$target->status!=='ready')$e->fail('status',__('该历史期初仍缺少完整依据，不能以调整释放代替期初核定。'));
            $day=$this->effectiveDay();$current=$this->resolve($type,$target,$day);
            if((int)($v['expected_revision']??-1)!==$current['revision_id'])$e->fail('expected_revision',__('释放配置已更新，请刷新后重试。'));
            $status=$v['status']??'';if(!in_array($status,['active','paused'],true))$e->fail('status',__('释放状态无效。'));
            $rate=null;
            if($type==='plan'){
                $rate=Amount::valid($v['daily_rate']??null);
                if(Amount::cmp($rate,'1')>0)$e->fail('daily_rate',__('日比例不能超过 100%。'));
                $amount=Amount::mul($target->amount,$rate);
            }else{
                $amount=Amount::valid($v['daily_amount']??null);
                if(Amount::cmp($amount,$e->account($target->account_id)->quota_total)>0)$e->fail('daily_amount',__('日释放量不能大于账户总额度。'));
            }
            DB::table('umi_release_revisions')->insert(['target_type'=>$type,'target_id'=>$target->$column,
                'account_id'=>$target->account_id,'operation_id'=>$op,'effective_on'=>$day,'status'=>$status,
                'daily_rate'=>$rate,'daily_amount'=>$amount,'reason'=>trim($v['reason']),'created_at'=>now()]);
        });
    }
}
