<?php
namespace App\Services\Market;

use App\Models\Market\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Deepro's own firm quotes, bounded again by the shared credit/maker policy at every fill. */
final class HongKongPlatformQuote
{
    public function enabled(Market $market): bool
    {
        return (bool)config('hk-price-products.platform_quotes.enabled',false) && HongKongPriceProduct::isMarket($market);
    }

    public function refresh(Market $market): void
    {
        if (!$this->enabled($market) || !$market->liq || !$market->status || !$market->trade_status) return;
        $key='markets_liquidity.'.$market->name.'.executable';
        $old=Cache::get($key);
        if (($old['received_at']??0)>CarbonImmutable::now()->timestamp-10) return;
        $lock=Cache::lock('hk-platform-quote.'.$market->id,15);
        if (!$lock->get()) return;
        try {
            $asset=AssetProfile::presentation($market->baseCurrency);
            app(HongKongPriceProduct::class)->definition($asset);
            $feed=app(HongKongMarketData::class);
            $book=$this->build($market,$feed->quote($asset),$feed->fx());
            Cache::put($key,$book,30);
            Cache::put('hk-platform-quote.last.'.$market->id,$book,604800);
        } finally {$lock->release();}
    }

    public function cached(Market $market,bool $displayClosed=false): ?array
    {
        if (!$this->enabled($market)) return null;
        try {$definition=app(HongKongPriceProduct::class)->definition(AssetProfile::presentation($market->baseCurrency));}
        catch (\Throwable $e) {return null;}
        if (($definition['suspended']??false) || !($definition['tradingEnabled']??false) || !config('hk-price-products.trading_enabled')) return null;
        $open=app(HongKongPriceProduct::class)->sessionOpen();
        if (!$open && !$displayClosed) return null;
        $book=Cache::get('markets_liquidity.'.$market->name.'.executable');
        if (!$book && !$open && $displayClosed) $book=Cache::get('hk-platform-quote.last.'.$market->id);
        $now=CarbonImmutable::now()->timestamp;
        if (!$book || ($book['source']??null)!=='deepro-platform-hk' || ($book['market']??null)!==$market->name
            || ($book['received_at']??0)>$now+5 || ($book['source_time']??0)>$now+5) return null;
        if ($open && (($book['session_open']??false)!==true || $now-$book['received_at']>20
            || $now-$book['source_time']>(int)config('hk-price-products.quote_max_age')
            || $now-($book['fx_time']??0)>(int)config('hk-price-products.fx_max_age'))) return null;
        if (!$open && $now-$book['source_time']>604800) return null;
        return $book;
    }

    public function build(Market $market,array $quote,array $fx): array
    {
        $asset=AssetProfile::presentation($market->baseCurrency);
        $definition=app(HongKongPriceProduct::class)->definition($asset);
        $now=CarbonImmutable::now()->timestamp;$open=app(HongKongPriceProduct::class)->sessionOpen();
        if (($definition['suspended']??false) || $market->name!==$asset['symbol'].'-USDT' || $market->quoteCurrency->symbol!=='USDT'
            || ($quote['currency']??null)!=='HKD') throw new \RuntimeException('hk_platform_identity_invalid');
        foreach ([$quote['price']??null,$fx['hkdPerUsdt']??null,$asset['unitRatio']??null] as $v)
            if (!is_string($v) || !preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D',$v) || bccomp($v,'0',18)<=0) throw new \RuntimeException('hk_platform_price_invalid');
        foreach ([[$quote['eventTime']??null,$open?(int)config('hk-price-products.quote_max_age'):604800],[$fx['eventTime']??null,(int)config('hk-price-products.fx_max_age')]] as [$at,$age])
            if (!is_int($at) || $at>$now+5 || $at<$now-$age) throw new \RuntimeException('hk_platform_source_expired');
        $policy=app(FundedLiquidity::class)->policy($market);
        if (!$policy || !in_array($policy->mode,['platform_credit','platform_maker'],true)) throw new \RuntimeException('hk_platform_policy_missing');
        $levels=5;$bps=20;$step=10;
        $mid=bcmul(bcdiv($quote['price'],$fx['hkdPerUsdt'],18),$asset['unitRatio'],18);
        $precision=min(18,max(0,(int)$market->quote_precision));
        $tick=(string)$market->quote_ticker_size;
        if (!preg_match('/^\d+(?:\.\d+)?$/D',$tick) || bccomp($tick,'0',18)<=0) $tick=bcdiv('1',bcpow('10',(string)$precision,0),18);
        $budget=bcdiv((string)$policy->max_quote_per_fill,(string)$levels,18);
        $out=['bids'=>[],'asks'=>[],'source'=>'deepro-platform-hk','market'=>$market->name,'received_at'=>$now,
            'source_time'=>$quote['eventTime'],'fx_time'=>$fx['eventTime'],'session_open'=>$open,
            'quote_source'=>$quote['source']??null,'fx_source'=>$fx['source']??null,'hkd_per_usdt'=>$fx['hkdPerUsdt']];
        foreach (['bids'=>-1,'asks'=>1] as $side=>$direction) {
            for ($i=0;$i<$levels;$i++) {
                $raw=bcdiv(bcmul($mid,(string)(10000+$direction*($bps+$i*$step)),18),'10000',18);
                $units=bcdiv($raw,$tick,0);$price=bcmul($units,$tick,$precision);
                if ($direction===1 && bccomp($price,$raw,18)<0) $price=bcadd($price,$tick,$precision);
                if (bccomp($price,'0',18)<=0) continue;
                if ($out[$side] && bccomp(end($out[$side])['price'],$price,18)===0) continue;
                $quantity=bcdiv($budget,$price,min(18,(int)$market->base_precision));
                if (bccomp($quantity,'0',18)>0) $out[$side][]=['price'=>$price,'quantity'=>$quantity];
            }
        }
        // Each quote interval is a new platform offer; consumed quantities persist within it.
        $out['snapshot']=hash('sha256',json_encode([$market->id,intdiv($now,15),$out['bids'],$out['asks'],$out['source_time'],$out['fx_time']]));
        return $out;
    }
}
