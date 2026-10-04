<?php
namespace App\Services\Operations;

use App\Models\User\User;
use App\Services\SystemMonitor\OperationsHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Incidents
{
    public const GUIDES = [
        'financial_recovery'=>['Financial recovery','Verified deposits or custody tasks require review','Open the wallet recovery workbench. Recheck independent chain evidence; never manually clear a balance or uncertain signature.'],
        'umi_recovery'=>['UMI recovery','Confirmed burns or settlement obligations require review','Open UMI operations. Preserve the original confirmation date and quote evidence; use the recovery queue.'],
        'monitoring'=>['Monitoring freshness','All service status may be stale','Check the health collector and its timer; obtain a fresh sample before closing.'],
        'containers'=>['Container runtime','Website or workers may be unavailable','Inspect container status and recent deployment logs; verify service responses after recovery.'],
        'data_disk'=>['Disk space','Database and queued work may stop','Inspect disk usage and retention. Preserve evidence and backups; do not delete business data.'],
        'postgres'=>['Database','User requests and background tasks may fail','Inspect database availability and connection limits, then verify a new health sample.'],
        'pitr_evidence'=>['Point-in-time recovery','Recovery evidence is incomplete','Check archived WAL and documented recovery evidence. A successful backup alone is not PITR acceptance.'],
        'failed_jobs'=>['Failed jobs','Some background tasks need review','Locate failed job identifiers and causes. Use the existing authorized retry workflow; do not clear failures to claim recovery.'],
        'queue_wait'=>['Queue waiting time','User requests may remain pending','Inspect worker availability and oldest waiting jobs. Confirm the queue age has recovered.'],
        'deposit_scans'=>['Live deposit scanning','New deposits may be delayed','Open the asset workbench, check the affected chain and last successful scan; preserve all scan cursors.'],
        'deposit_backfill'=>['Historical deposit backfill','Historical deposits may still be pending','Check the backfill lane and progress separately from live scans; never skip ranges to clear an alert.'],
        'trx_scans'=>['TRX scanning','TRX deposits may be delayed','Inspect TRX scan progress and resource errors through the existing monitor.'],
        'backup_freshness'=>['Backup freshness','Latest recovery point may be too old','Check the backup timer and protected backup location; verify a new successful backup.'],
        'backup_restore'=>['Database backup restore','Backup recoverability is unverified','Run the documented isolated restore procedure and retain evidence. Never restore over production data.'],
    ];

    private function checks(): array {
        $report=app(OperationsHealth::class)->summary();
        $rows=['monitoring'=>['status'=>$report['fresh']?'ok':'unknown','sample_at'=>$report['generated_at'],'fresh'=>$report['fresh']]];
        if ($report['fresh']) foreach($report['checks'] as $c) $rows[$c['name']]=['status'=>$c['status'],'sample_at'=>$report['generated_at'],'fresh'=>true,'chains'=>$c['chains']??[]];
        if(\Illuminate\Support\Facades\Schema::hasTable('deposit_review_events')) {
            $count=DB::table('deposit_review_events')->where('status','open')->count()+DB::table('custody_transfers')->where('status','review')->count();
            $critical=DB::table('deposit_review_events')->where('status','open')->where('reason','DEPOSIT_POST_CREDIT_CHAIN_CONFLICT')->exists();
            $rows['financial_recovery']=['status'=>$critical?'critical':($count?'warning':'ok'),'sample_at'=>now()->toIso8601String(),'fresh'=>true,'attention'=>$count];
        }
        if(config('umi-v2.funded_enabled') && \Illuminate\Support\Facades\Schema::hasTable('umi_v2_recovery_tasks')) {
            $overview=app(\App\Services\Umi\V2\FundedRecovery::class)->overview();
            $count=(int)($overview['missing_points']??0)+(int)($overview['unresolved_tasks']??0)+(int)($overview['stale_burns']??0);
            $rows['umi_recovery']=['status'=>$count?'warning':'ok','sample_at'=>now()->toIso8601String(),'fresh'=>true,'attention'=>$count];
        }
        return $rows;
    }

    /**
     * Only writes operational metadata; no RPC, balance or channel mutations.
     * Bind ISO instants because QueryBuilder strips offsets from Carbon objects.
     */
    public function sync(): array {
        $checks=$this->checks();
        DB::transaction(function()use($checks){
            // PostgreSQL advisory lock serializes scheduled/manual imports, including absent rows.
            DB::select('SELECT pg_advisory_xact_lock(?)',[926200001]);
            foreach($checks as $key=>$sample) {
                if (!isset(self::GUIDES[$key])) continue;
                $row=DB::table('operations_incidents')->where('check_key',$key)->lockForUpdate()->first();
                $bad=$sample['status']!=='ok';
                if (!$row && !$bad) continue;
                if (!$row) {
                    $id=DB::table('operations_incidents')->insertGetId(['check_key'=>$key,'severity'=>$sample['status'],'status'=>'open',
                        'due_at'=>now()->addMinutes($sample['status']==='critical'?30:240)->toIso8601String(),'opened_at'=>now()->toIso8601String(),'last_unhealthy_at'=>now()->toIso8601String(),
                        'sample_at'=>$sample['sample_at'],'evidence'=>json_encode($sample),'created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()]);
                    History::append('incident',$id,'incident.opened',['sample'=>$sample]);continue;
                }
                if ($sample['sample_at'] && $row->sample_at && CarbonImmutable::parse($sample['sample_at'])->lte(CarbonImmutable::parse($row->sample_at))) continue;
                $update=['sample_at'=>$sample['sample_at'],'evidence'=>json_encode($sample),'updated_at'=>now()->toIso8601String()];
                $action=null;
                if ($bad) {
                    $update+=['last_unhealthy_at'=>now()->toIso8601String(),'recovery_at'=>null,'severity'=>$sample['status']];
                    if($row->status==='resolved') {$update+=['status'=>'open','resolved_at'=>null,'opened_at'=>now()->toIso8601String(),'due_at'=>now()->addMinutes($sample['status']==='critical'?30:240)->toIso8601String()];$action='incident.reopened';}
                    elseif($row->recovery_at || $row->severity!==$sample['status']) $action='incident.changed';
                } elseif(!$row->recovery_at && CarbonImmutable::parse($sample['sample_at'])->gte(CarbonImmutable::parse($row->last_unhealthy_at))) {
                    $update['recovery_at']=$sample['sample_at'];$action='incident.recovery_detected';
                }
                if ($action) {$update['revision']=$row->revision+1;History::append('incident',$row->id,$action,['sample'=>$sample]);}
                DB::table('operations_incidents')->where('id',$row->id)->update($update);
            }
        });
        return $checks;
    }

    public function update(int $id,array $data,int $actor): void {
        $this->sync();
        DB::transaction(function()use($id,$data,$actor){
            $row=DB::table('operations_incidents')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            if(History::replay($data['request_key'],'incident',$id,$actor))return;
            if((int)$row->revision!==(int)$data['revision'])throw ValidationException::withMessages(['revision'=>__('Record changed. Refresh before saving.')]);
            if(!empty($data['assigned_to']) && !self::operators()->whereKey($data['assigned_to'])->exists())throw ValidationException::withMessages(['assigned_to'=>__('Invalid operator.')]);
            $snapshot=$this->checks()[$row->check_key] ?? null;
            if($data['status']==='resolved' && (!$snapshot || !$snapshot['fresh'] || $snapshot['status']!=='ok' || !CarbonImmutable::parse($snapshot['sample_at'])->gte(CarbonImmutable::parse($row->last_unhealthy_at)))) {
                throw ValidationException::withMessages(['status'=>__('A fresh healthy sample after the last failure is required to close this incident.')]);
            }
            $fields=['status'=>$data['status'],'assigned_to'=>$data['assigned_to']??null,'due_at'=>$data['due_at'],
                'revision'=>$row->revision+1,'resolved_at'=>$data['status']==='resolved'?now()->toIso8601String():null,'updated_at'=>now()->toIso8601String()];
            DB::table('operations_incidents')->where('id',$id)->update($fields);
            History::append('incident',$id,'incident.updated',['before'=>array_intersect_key((array)$row,$fields),'after'=>$fields,'recovery_sample'=>$data['status']==='resolved'?$snapshot:null],$actor,$data['reason'],$data['request_key']);
        });
    }
    public static function operators() { return User::role(['superadmin','perm_settings'])->where('deleted',false)->where('deactivated',false); }
    public function list(array $filters): array {
        $checks=$this->checks();
        $query=DB::table('operations_incidents');
        if(!empty($filters['status']))$query->where('status',$filters['status']);
        if(!empty($filters['assigned_to']))$query->where('assigned_to',$filters['assigned_to']);
        if(!empty($filters['overdue']))$query->where('due_at','<',now()->toIso8601String())->where('status','!=','resolved');
        $rows=$query->orderByRaw("CASE WHEN status='resolved' THEN 1 ELSE 0 END")->orderBy('due_at')->paginate(30)->withQueryString();
        $rows->getCollection()->transform(function($r)use($checks){
            $r->evidence=json_decode($r->evidence,true);$r->guide=self::GUIDES[$r->check_key];$r->current=$checks[$r->check_key]??['status'=>'unknown','fresh'=>false,'sample_at'=>null];
            $r->can_resolve=$r->current['fresh'] && $r->current['status']==='ok' && CarbonImmutable::parse($r->current['sample_at'])->gte(CarbonImmutable::parse($r->last_unhealthy_at));
            return $r;
        });
        return ['incidents'=>$rows,'health'=>app(OperationsHealth::class)->summary(),'operators'=>self::operators()->orderBy('id')->get(['id','name']),'filters'=>$filters];
    }
}
