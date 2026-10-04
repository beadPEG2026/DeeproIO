<?php
namespace App\Services\Umi\V2;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\{DB,Schema};

/** Business dates are contiguous, complete days; an old hole never silently advances the cursor. */
final class FundedCalendar
{
    public function nextDay(): ?string
    {
        $first=DB::table('umi_v2_cycles as c')->join('umi_v2_live_intents as i','i.cycle_id','=','c.id')
            ->whereIn('c.status',['active','completed'])->whereNotNull('c.starts_on')->min('c.starts_on');
        if (!$first) return null;
        $next=CarbonImmutable::parse($first,config('umi-v2.timezone'))->toDateString();
        foreach (DB::table('umi_v2_live_daily_runs')->where('business_date','>=',$next)->orderBy('business_date')->pluck('business_date') as $day) {
            if ($day!==$next) break;
            $next=CarbonImmutable::parse($next)->addDay()->toDateString();
        }
        return $next;
    }

    public function rate(string $day): string
    {
        if (!Schema::hasTable('umi_v2_rate_schedule')) throw new DomainException('UMI 结算配置升级尚未完成。');
        $rate=DB::table('umi_v2_rate_schedule')->where('effective_on','<=',$day)->orderByDesc('effective_on')->value('static_rate');
        if ($rate===null) throw new DomainException('该历史业务日缺少已核定日率快照，请先核对历史规则。');
        return (string)$rate;
    }

    public function status(): array
    {
        $next=$this->nextDay();$last=now(config('umi-v2.timezone'))->subDay()->toDateString();
        $gap=$next && DB::table('umi_v2_live_daily_runs')->where('business_date','>',$next)->exists();
        $rate=null;$unverified=false;if($next)try{$rate=$this->rate($next);}catch(DomainException){$unverified=true;}
        return ['next_day'=>$next,'last_complete_day'=>$last,'due'=>$next!==null && $next<=$last,
            'historical_gap'=>$gap,'rate'=>$rate,'rate_unverified'=>$unverified,'timezone'=>config('umi-v2.timezone')];
    }
}
