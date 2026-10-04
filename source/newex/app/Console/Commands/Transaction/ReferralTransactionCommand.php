<?php
namespace App\Console\Commands\Transaction;

use App\Services\Referral\ExchangeRewards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Log};

class ReferralTransactionCommand extends Command
{
    protected $signature = 'transaction:referral-credits {--dry-run : Inspect without changing balances} {--limit=500 : Maximum rewards per run}';
    protected $description = 'Credit proven exchange fee rewards once, preserving real/virtual domains';

    public function handle(ExchangeRewards $rewards): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (!$limit || $limit < 1 || $limit > 5000) { $this->error('Invalid limit (1–5000)'); return self::FAILURE; }
        $pending = fn()=>DB::table('referral_transactions')->where('is_credited',false)->whereNotNull('event_key')->whereIn('credit_status',['pending','failed']);
        $summary = ['dry_run'=>(bool)$this->option('dry-run'), 'eligible'=>$pending()->count(), 'review'=>DB::table('referral_transactions')->where('is_credited',false)->where('credit_status','review')->count(), 'credited'=>0,'failed'=>0,'skipped'=>0];
        if ($summary['dry_run']) { $this->line(json_encode($summary)); return self::SUCCESS; }
        $locked = DB::selectOne('SELECT pg_try_advisory_lock(741029, 20260920) AS acquired')->acquired;
        if (!$locked) { $this->line('{"already_running":true}'); return self::SUCCESS; }
        try {
            foreach ($pending()->orderBy('id')->limit($limit)->pluck('id') as $id) {
                try { $status=$rewards->credit((int)$id); $summary[$status]++; }
                catch (\Throwable $e) {
                    $summary['failed']++;
                    DB::table('referral_transactions')->where('id',$id)->where('is_credited',false)->update(['credit_status'=>'failed','last_error'=>'credit_error','updated_at'=>now()]);
                    Log::error('Exchange referral credit failed', ['referral_id'=>$id,'error_type'=>get_class($e)]);
                }
            }
            $summary['remaining']=$pending()->count(); $summary['finished_at']=now()->toIso8601String();
            Cache::put('deepro.exchange_referral.last_run',$summary,now()->addDays(7));
            $this->line(json_encode($summary));
            return $summary['failed'] ? self::FAILURE : self::SUCCESS;
        } finally { DB::select('SELECT pg_advisory_unlock(741029, 20260920)'); }
    }
}
