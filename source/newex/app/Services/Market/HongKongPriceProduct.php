<?php

namespace App\Services\Market;

use App\Models\Market\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class HongKongPriceProduct
{
    public static function isAsset(array $asset): bool { return ($asset['instrumentType'] ?? null) === 'equity_price_reference'; }
    public static function isCurrency(object $currency): bool { return self::isAsset(AssetProfile::reference($currency)); }
    public static function isMarket(Market $market): bool { return self::isCurrency($market->baseCurrency) || in_array($market->name,array_map(fn($s)=>$s.'-USDT',array_keys(config('hk-price-products.assets',[]))),true); }

    public function definition(array $asset): array
    {
        $expected = config('hk-price-products.assets.'.$asset['symbol']);
        if (!$expected || !self::isAsset($asset)) throw new \RuntimeException('hk_product_not_registered');
        foreach (['securityCode','instrumentType','marketSource','referenceCurrency','unitRatio','exchange'] as $key)
            if (($asset[$key] ?? null) !== $expected[$key]) throw new \RuntimeException('hk_product_identity_mismatch');
        return $expected;
    }

    public function sessionOpen(?CarbonImmutable $time = null): bool
    {
        return $this->session($time)['is_open'];
    }

    /** Continuous sessions only; this product does not run HKEX auction matching. */
    public function session(?CarbonImmutable $time = null): array
    {
        $now = ($time ?? CarbonImmutable::now())->setTimezone('Asia/Hong_Kong');
        $windows = $this->sessionWindows($now);
        $open = false; $close = null; $next = null;
        foreach ($windows as [$start, $end]) {
            if ($now >= $start && $now < $end) { $open = true; $close = $end; }
        }
        // Do not guess holidays in an unreviewed year, including at the year boundary.
        for ($i = 0; $i < 370 && !$next; ++$i) {
            $day = $now->startOfDay()->addDays($i);
            if (!in_array($day->year, config('hk-price-products.calendar_years', []), true)) break;
            foreach ($this->sessionWindows($day) as [$start]) if ($start > $now) { $next = $start; break; }
        }
        $state = $open ? 'open' : (count($windows) === 2 && $now >= $windows[0][1] && $now < $windows[1][0] ? 'lunch' : 'closed');
        return ['state'=>$state, 'is_open'=>$open, 'timezone'=>'Asia/Hong_Kong',
            'server_time'=>$now->toIso8601String(), 'closes_at'=>$close?->toIso8601String(),
            'next_open_at'=>$next?->toIso8601String(),
            'half_day'=>in_array($now->toDateString(), config('hk-price-products.half_days', []), true),
            'calendar_available'=>in_array($now->year, config('hk-price-products.calendar_years', []), true)];
    }

    private function sessionWindows(CarbonImmutable $day): array
    {
        if (!in_array($day->year, config('hk-price-products.calendar_years', []), true) || $day->isWeekend()
            || in_array($day->toDateString(), config('hk-price-products.holidays', []), true)) return [];
        $windows = [[$day->startOfDay()->addMinutes(570), $day->startOfDay()->addMinutes(720)]];
        if (!in_array($day->toDateString(), config('hk-price-products.half_days', []), true))
            $windows[] = [$day->startOfDay()->addMinutes(780), $day->startOfDay()->addMinutes(960)];
        return $windows;
    }

    public function marketSession(Market $market): ?array
    {
        if (!self::isMarket($market)) return null;
        $session = $this->session();
        $definition = config('hk-price-products.assets.'.$market->baseCurrency->symbol, []);
        $enabled = $market->status && $market->trade_status && $market->baseCurrency->status && $market->quoteCurrency->status
            && config('hk-price-products.trading_enabled') && ($definition['tradingEnabled'] ?? false) && !($definition['suspended'] ?? false);
        if (!$enabled) { $session['state'] = 'suspended'; $session['next_open_at'] = null; }
        $session['can_trade'] = $enabled && $session['is_open'];
        return $session;
    }

    public function quote(array $asset, bool $includeFx = true): array
    {
        $this->definition($asset);
        $feed = app(HongKongMarketData::class);
        $raw = $feed->quote($asset);
        $fresh = $this->fresh($raw['eventTime'], config('hk-price-products.quote_max_age'));
        $fx=null; $converted=null;
        try {
            if ($includeFx) $fx=$feed->fx();
            if ($fx && $this->fresh($fx['eventTime'],config('hk-price-products.fx_max_age')))
                $converted=bcmul(bcdiv($raw['price'],$fx['hkdPerUsdt'],18),$asset['unitRatio'],18);
        } catch (\Throwable $e) { /* An unavailable FX quote does not invalidate the native HKD reference. */ }
        $result = ['symbol'=>$asset['symbol'], 'price'=>$converted,
            // Native HKD history is never relabelled as historical USDT performance.
            'high'=>null, 'low'=>null, 'change'=>null, 'currency'=>'USDT',
            'underlyingPrice'=>$raw['price'], 'underlyingCurrency'=>'HKD', 'underlyingChange'=>$raw['change'],
            'underlyingHigh'=>$raw['high'], 'underlyingLow'=>$raw['low'],
            'referenceCurrency'=>'HKD', 'source'=>$raw['source'], 'reference_only'=>true,
            'sourceTime'=>gmdate('c',$raw['eventTime']), 'receivedAt'=>$raw['receivedAt'],
            'referenceAgeSeconds'=>max(0,CarbonImmutable::now()->timestamp-$raw['eventTime']),
            'fx'=>$fx, 'fxUnavailable'=>$converted===null, 'stale'=>!$fresh, 'unavailable'=>!$fresh || $converted===null,
            'marketStatus'=>$this->sessionOpen() ? 'reference_session' : 'closed',
            'openState'=>$fresh && $this->sessionOpen()];
        Cache::put('hk-product.snapshot.'.$asset['symbol'], $result, 120);
        return $result;
    }

    public function tradingReason(Market $market, bool $refresh = true, bool $requireMarketEnabled = true, bool $requireSessionOpen = true): ?string
    {
        if (!self::isMarket($market)) return null;
        try {
            // Re-read the switches in the matching transaction: a queued order must obey a later pause.
            $current=Market::whereKey($market->id)->lockForUpdate()->first();
            if (!$current || !$current->status || !$current->baseCurrency->status || !$current->quoteCurrency->status
                || ($requireMarketEnabled && !$current->trade_status)) return 'Price reference product trading is not enabled';
            $asset = AssetProfile::presentation($current->baseCurrency);
            $definition = $this->definition($asset);
            if ($current->name !== $asset['symbol'].'-USDT' || $current->quoteCurrency->symbol !== 'USDT') return 'Price reference product identity is invalid';
            if (!config('hk-price-products.trading_enabled') || !($definition['tradingEnabled'] ?? false)) return 'Price reference product trading is not enabled';
            if ($definition['suspended'] ?? false) return 'Trading paused';
            if ($requireSessionOpen && !$this->sessionOpen()) return 'Market closed. Trading resumes at the next session.';
            // Both customer orders and automatic liquidity obey the same session gate.
            return null;
        } catch (\Throwable $e) { return 'Reference price is unavailable or overdue'; }
    }

    private function fresh(int $time, int $age): bool { return $time > 0 && $time <= CarbonImmutable::now()->timestamp+5 && CarbonImmutable::now()->timestamp-$time <= $age; }
}
