<?php
namespace App\Console\Commands\Futures;
use App\Models\Order\FuturesContract;
use App\Services\Order\FuturesFundingSettlement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
class ProcessFundingFeesCommand extends Command {
    protected $signature='futures:funding-fees';
    protected $description='Settle due funding periods with immutable idempotent receipts';
    public function handle(FuturesFundingSettlement $settlement): int {
        $rate=(string)\Setting::get('futures.funding_fee_rate','0.01');
        $hours=(int)\Setting::get('futures.funding_fee_interval_hours',8);
        if(!is_numeric($rate)||$hours<1||$hours>168){$this->error('Invalid funding settings');return 1;}
        if(bccomp($rate,'0',8)===0)return 0;
        $now=now();$cutoff=$now->copy()->subHours($hours);$processed=0;$errors=0;
        $ids=FuturesContract::where('status','active')->where(fn($q)=>$q->whereNull('last_funding_fee_at')->orWhere('last_funding_fee_at','<=',$cutoff))->orderByRaw('COALESCE(last_funding_fee_at, activated_at, created_at) ASC')->limit(500)->pluck('id');
        foreach($ids as$id){try{$processed+=(int)$settlement->settle((string)$id,$rate,$hours,$now);}catch(\Throwable$e){$errors++;Log::warning('Funding settlement needs attention',['contract_id'=>$id,'exception'=>get_class($e)]);}}
        $this->info("Processed {$processed} positions. Errors: {$errors}");return $errors?1:0;
    }
}
