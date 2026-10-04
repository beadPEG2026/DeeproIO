<?php
namespace App\Services\Umi\V2;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Recovery never broadcasts, changes a quote timestamp, or reverses a chain transaction. */
final class FundedRecovery
{
    public function discover(): void
    {
        DB::table('umi_v2_live_burn_lots as l')->where('l.status','confirmed')->where('l.purpose','withdrawal')
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('umi_v2_stock_point_entries as p')->whereColumn('p.withdrawal_id','l.withdrawal_id')->where('p.kind','burn_credit'))
            ->orderBy('l.id')->select('l.*')->chunkById(100,function($rows){foreach($rows as $lot) {
                DB::table('umi_v2_recovery_tasks')->insertOrIgnore(['task_key'=>'points:'.$lot->id,'kind'=>'burn_points','reference_id'=>$lot->id,
                    'member_id'=>$lot->member_id,'details_json'=>json_encode(['withdrawal_id'=>$lot->withdrawal_id,'confirmed_at'=>$lot->confirmed_at],JSON_THROW_ON_ERROR),
                    'created_at'=>FundedTime::database(now()),'updated_at'=>FundedTime::database(now())]);
            }},'l.id','id');
    }

    public function retry(int $id, int $actor=0): array
    {
        return DB::transaction(function()use($id,$actor){
            $task=DB::table('umi_v2_recovery_tasks')->where('id',$id)->lockForUpdate()->first();
            if (!$task || $task->kind!=='burn_points') throw new DomainException('恢复任务不存在。');
            if ($task->status==='resolved') return ['resolved'=>true,'replayed'=>true];
            // creditPointsForLot binds both quotes to original finality time and has a unique withdrawal key.
            $errorCode='historical_quote_unavailable';
            try {$ok=app(FundedBurn::class)->creditPointsForLot((int)$task->reference_id);}
            catch(\Throwable $error){$ok=false;$errorCode='reconciliation_'.class_basename($error);report($error);}
            $now=FundedTime::database(now());
            DB::table('umi_v2_recovery_tasks')->where('id',$id)->update(['status'=>$ok?'resolved':'waiting_evidence','attempts'=>$task->attempts+1,
                'error_code'=>$ok?null:$errorCode,'last_attempt_at'=>$now,'next_attempt_at'=>$ok?null:FundedTime::database(now()->addHour()),
                'resolved_at'=>$ok?$now:null,'updated_at'=>$now]);
            DB::table('umi_v2_recovery_actions')->insert(['task_id'=>$id,'action'=>'retry_points','actor_id'=>$actor,'reason'=>'Retry using original confirmed burn and historical quote evidence',
                'result_json'=>json_encode(['resolved'=>$ok,'attempt'=>$task->attempts+1,'error_code'=>$ok?null:$errorCode],JSON_THROW_ON_ERROR),'created_at'=>$now]);
            return ['resolved'=>$ok,'replayed'=>false];
        },3);
    }

    public function run(): array
    {
        $this->discover();$resolved=0;$waiting=0;
        foreach(DB::table('umi_v2_recovery_tasks')->whereIn('status',['pending','waiting_evidence'])
            ->where(fn($q)=>$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',FundedTime::database(now())))
            ->orderBy('id')->limit(50)->pluck('id') as $id) {
            $result=$this->retry((int)$id);$result['resolved']?$resolved++:$waiting++;
        }
        return compact('resolved','waiting');
    }

    public function overview(): array
    {
        $missing=DB::table('umi_v2_live_burn_lots as l')->where('l.status','confirmed')->where('l.purpose','withdrawal')
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('umi_v2_stock_point_entries as p')->whereColumn('p.withdrawal_id','l.withdrawal_id')->where('p.kind','burn_credit'))->count();
        return ['missing_points'=>$missing,'unresolved_tasks'=>DB::table('umi_v2_recovery_tasks')->where('status','!=','resolved')->count(),
            'stale_burns'=>DB::table('umi_v2_live_burn_lots')->whereIn('status',['pending','custody'])->where('created_at','<',FundedTime::database(now()->subHour()))->count(),
            'awaiting_topup'=>DB::table('umi_v2_withdrawals')->where('status','awaiting_topup')->count(),
            'tasks'=>DB::table('umi_v2_recovery_tasks')->orderByDesc('id')->limit(50)->get()];
    }
}
