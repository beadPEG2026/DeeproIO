<?php
namespace App\Services\Order;
use App\Models\Order\FuturesContract;
use App\Models\Wallet\Wallet;
use App\Events\WalletUpdated;
use App\Services\Market\VerifiedDerivativePrice;
use App\Services\Math\ExactDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
final class FuturesFundingSettlement {
    /** One current interval per due position. Downtime never fabricates historical prices. */
    public function settle(string $id, string $rate, int $hours, CarbonInterface $now): bool {
        if ($hours<1 || $hours>168) throw new \InvalidArgumentException('Invalid funding interval.');
        $rate=ExactDecimal::normalize($rate,8);
        return DB::transaction(function()use($id,$rate,$hours,$now) {
            $p=FuturesContract::with(['market','user'])->whereKey($id)->lockForUpdate()->first();
            if (!$p || $p->status!=='active') return false;
            $previous=$p->last_funding_fee_at ?: ($p->activated_at ?: $p->created_at);
            if (!$previous || $previous->gt($now->copy()->subHours($hours))) return false;
            $period=(string)(intdiv($now->timestamp,$hours*3600)*($hours*3600)).':'.$hours;
            if(DB::table('funding_fee_distributions')->where('futures_contract_id',$id)->where('period_key',$period)->exists())return false;
            $quote=app(VerifiedDerivativePrice::class)->forContract($p);
            $notional=bcmul((string)$p->quantity,$quote['price'],18);
            $theoretical=bcdiv(bcmul($notional,$rate,26),'100',18);
            $before=(string)$p->balance;
            $wantedDelta=$p->is_long?bcsub('0',$theoretical,18):$theoretical;
            $after=bcadd($before,$wantedDelta,18);
            if(bccomp($after,'0',18)<0)$after='0';
            $delta=bcsub($after,$before,18);
            $actual=$p->is_long?bcsub('0',$delta,18):$delta;
            $paid=bccomp($delta,'0',18)<0?bcsub('0',$delta,18):'0';
            $wantedPaid=bccomp($wantedDelta,'0',18)<0?bcsub('0',$wantedDelta,18):'0';
            $p->balance=$after;$p->total_funding_fee_paid=bcadd((string)($p->total_funding_fee_paid??'0'),$paid,18);$p->last_funding_fee_at=$now;$p->save();
            DB::table('funding_fee_distributions')->insert(['user_id'=>$p->user_id,'futures_contract_id'=>$p->id,'market_id'=>$p->market_id,'period_key'=>$period,'funding_fee_amount'=>$actual,'theoretical_fee'=>$theoretical,'balance_delta'=>$delta,'shortfall'=>bcsub($wantedPaid,$paid,18),'position_size'=>$notional,'funding_rate'=>$rate,'is_long'=>$p->is_long,'price_source'=>$quote['source'],'distributed_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            $wallet=Wallet::where('user_id',$p->user_id)->where('currency_id',$p->market->quote_currency_id)->first();
            if($wallet)DB::afterCommit(fn()=>event(new WalletUpdated($wallet)));
            return true;
        },3);
    }
}
