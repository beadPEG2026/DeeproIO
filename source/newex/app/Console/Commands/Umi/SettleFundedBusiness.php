<?php
namespace App\Console\Commands\Umi;

use App\Services\Umi\Business\{Rules,Settlement};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache,DB};

final class SettleFundedBusiness extends Command
{
    protected $signature='umi:settle-funded';
    protected $description='Settle completed UMI business dates from funded inventory';
    public function handle(Rules $rules,Settlement $settlement):int {
        if (config('umi-v2.funded_enabled')) { return self::SUCCESS; }
        Cache::put('deepro.health.umi-settlement-attempt',time(),86400);
        if(!$rules->live()) return self::SUCCESS;
        $state=DB::table('umi_business_state')->find(1);
        if(!$state->rule_id || $state->paused){Cache::put('deepro.health.umi-settlement',time(),180);return self::SUCCESS;}
        // An empty business must not create settlement days that block the approved legacy cutover.
        if(!DB::table('umi_business_plans')->exists() && !DB::table('umi_business_entries')->exists()) {
            Cache::put('deepro.health.umi-settlement',time(),180);Cache::forget('deepro.umi.settlement_error');return self::SUCCESS;
        }
        $last=now(config('umi-business.defaults.timezone'))->subDay()->toDateString();
        $next=\Carbon\CarbonImmutable::parse($state->business_date)->addDay()->toDateString();
        try {
            $settlement->unlockDue();
            if($next<=$last)$settlement->advance(0,$next,'funded-day-'.$next);
            Cache::put('deepro.health.umi-settlement',time(),180);
            Cache::forget('deepro.umi.settlement_error');
            return self::SUCCESS;
        }catch(\Throwable $e){report($e);Cache::put('deepro.umi.settlement_error','日结尚未完成，请核对资金池和业务记录。',86400);$this->error('UMI 日结未完成，未发放部分收益。请核对资金池。');return self::FAILURE;}
    }
}
