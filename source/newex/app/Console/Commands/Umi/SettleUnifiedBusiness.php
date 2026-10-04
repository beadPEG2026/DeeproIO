<?php
namespace App\Console\Commands\Umi;

use App\Services\Umi\V2\{FundedRuntime,FundedSettlement};
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache,DB};

/** Only the unified calculator runs; a disabled operator switch performs no writes. */
final class SettleUnifiedBusiness extends Command
{
    protected $signature='umi:settle-unified';
    protected $description='结算统一 UMI 账户已结束的业务日';
    public function handle(FundedSettlement $settlement): int
    {
        if (!config('umi-v2.funded_enabled') || !FundedRuntime::schemaReady()) return self::SUCCESS;
        $settings=DB::table('umi_v2_live_settings')->where('id',1)->first();
        if (!$settings?->settlement_enabled) return self::SUCCESS;
        $lock=Cache::lock('deepro.umi.unified-daily-settlement',120);
        if (!$lock->get()) return self::SUCCESS;
        try {
            $calendar=app(\App\Services\Umi\V2\FundedCalendar::class);$state=$calendar->status();
            if ($state['due']) $settlement->runDay($state['next_day'],$calendar->rate($state['next_day']),0);
            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);$this->error('统一 UMI 日结未完成，请核对配置和资金池。');return self::FAILURE;
        } finally {$lock->release();}
    }
}
