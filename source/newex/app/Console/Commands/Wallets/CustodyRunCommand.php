<?php
namespace App\Console\Commands\Wallets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Cache};
use App\Services\Custody\CustodyService;

class CustodyRunCommand extends Command {
    protected $signature='wallets:custody-run {--limit=20}';
    protected $description='Fair, bounded custody planning and persisted transaction reconciliation';
    public function handle(CustodyService $service): int {
        $limit=max(1,min(100,(int)$this->option('limit'))); $stop=microtime(true)+45;
        $lock=Cache::lock('custody:planner',600);if(!$lock->get())return 0;
        try {
            $rows=DB::table('deposits')->where('status',DEPOSIT_CONFIRMED)->where('wallet_transfer_status','review')
                ->where(fn($q)=>$q->whereNull('sweep_retry_at')->orWhere('sweep_retry_at','<=',now()))
                ->orderByRaw('sweep_attempted_at ASC NULLS FIRST')->orderBy('id')->limit($limit)->pluck('id');
            foreach($rows as $id) {
                if(microtime(true)>$stop)break;
                $error=null;$row=null;
                try{$row=$service->sweep($id);if(!$row)$error='CUSTODY_VERIFIED_RECEIPT_REQUIRED';}
                catch(\Throwable $e){$error=preg_match('/^CUSTODY_[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'CUSTODY_PLANNING_REVIEW';}
                DB::table('deposits')->where('id',$id)->update(['sweep_attempted_at'=>now(),'sweep_retry_at'=>$error?now()->addMinutes(15):null,'sweep_error'=>$error]);
            }
            $pending=DB::table('custody_transfers as t')->join('custody_networks as n','n.chain','=','t.chain')
                ->where('t.purpose','sweep')->where('t.status','awaiting_approval')->whereNotNull('t.deposit_id')
                ->where('n.enabled',true)->where('n.auto_sweep',true)->where('n.auto_sweep_scope','all_verified')
                ->orderByRaw('t.last_attempt_at ASC NULLS FIRST')->orderBy('t.id')->limit($limit)->pluck('t.id');
            foreach($pending as $id) {
                if(microtime(true)>$stop)break;
                DB::table('custody_transfers')->where('id',$id)->update(['last_attempt_at'=>now()]);
                try{app(\App\Services\Custody\SweepAutomation::class)->approve($id);}catch(\Throwable $e){$this->warn('Sweep '.$id.': automatic verification requires review.');}
            }
        }finally{$lock->release();}
        // Rotate attempted tasks before a provider call: one uncertain sender cannot starve other chains.
        $ids=DB::table('custody_transfers')->whereIn('status',['confirming','review','prepared','approved'])
            ->orderByRaw('last_attempt_at ASC NULLS FIRST')->orderBy('id')->limit($limit)->pluck('id');
        $checked=0;
        foreach($ids as $id){if(microtime(true)>$stop)break;DB::table('custody_transfers')->where('id',$id)->update(['last_attempt_at'=>now()]);$service->run($id);$checked++;}
        // Cold-rule planning has its own rotation and follows existing customer sends.
        $rules=DB::table('cold_storage')->where('status',true)->whereNotNull('approved_at')->orderBy('id')->pluck('id')->all();
        $offset=(int)Cache::get('custody:cold-rule-offset',0);
        if($rules){$offset%=count($rules);$rules=array_merge(array_slice($rules,$offset),array_slice($rules,0,$offset));}
        foreach($rules as $id){if(microtime(true)>$stop)break;Cache::put('custody:cold-rule-offset',++$offset,86400);try{$service->cold($id);}catch(\Throwable $e){$this->warn('Cold rule '.$id.': configuration or provider review required.');}}
        $this->info('Custody cycle completed: '.$checked.' tasks checked.');return 0;
    }
}
