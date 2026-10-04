<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Market\Market as MarketResource;
use App\Http\Resources\Market\MarketCollection;
use App\Models\Market\Market;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\Market\MarketService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Setting;

class MarketController extends Controller
{
    public function index()
    {
        if (is_mobile_instance()) {
            return redirect()->route('markets.lite');
        }

        return Inertia::render('Market/Markets');
    }

    public function spot()
    {
        if (is_mobile_instance()) {
            return Inertia::render('MarketLite/Markets', [
                'initialSort' => 'spot',
            ]);
        }

        return Inertia::render('Market/Markets', [
            'initialSort' => 'spot',
        ]);
    }

    public function futuresIndex()
    {
        if (is_mobile_instance()) {
            return Inertia::render('MarketLite/Markets', [
                'initialSort' => 'futures',
            ]);
        }

        return Inertia::render('Market/Markets', [
            'initialSort' => 'futures',
        ]);
    }

    public function optionsIndex()
    {
        if (is_mobile_instance()) {
            return Inertia::render('MarketLite/Markets', [
                'initialSort' => 'options',
            ]);
        }

        return Inertia::render('Market/Markets', [
            'initialSort' => 'options',
        ]);
    }

    public function indexLite()
    {
        return Inertia::render('MarketLite/Markets');
    }

    public function show(Market $market, $chart = '')
    {
        $market = (new MarketService())->getMarket($market->id);

        if (!$market) {
            throw new ModelNotFoundException();
        }

        if (is_mobile_instance()) {
            return redirect()->route('market.lite', ['market' => $market->name, 'asset' => request('asset') === 'info' ? 'info' : null]);
        }

        $currencyRepository = (new CurrencyRepository())->getQuoteCurrencies();
        $now = \Carbon\Carbon::now();

        return Inertia::render('Market/Market', [
            'fee' => Setting::get('trade.taker_fee', INITIAL_TRADE_TAKER_FEE),

            /*
             * 原本的行情资源。
             */
            'market' => new MarketResource($market),

            /*
             * 单独传给前端，避免 MarketResource 没有 id 时，
             * Vue 拿不到 market.data.id，导致 kline-delete-time 不请求。
             */
            'marketId' => (int) $market->id,
            'marketName' => $market->name,
            'stockAsset' => collect(app(\App\Services\Market\StockCatalog::class)->assets())->firstWhere('symbol', $market->baseCurrency->symbol),

            'quotes' => $currencyRepository,
            'futures' => false,
            'isMobile' => is_mobile_instance(),
            'chart' => $chart,

            /*
             * 页面进入时的服务器时间。
             * 前端用它和 K 线删除时间接口返回的 deleted_at 比较。
             */
            'serverTime' => $now->toIso8601String(),
        ]);
    }

    public function showLite(Market $market, $chart = '')
    {
        $market = (new MarketService())->getMarket($market->id);

        if (!$market) {
            throw new ModelNotFoundException();
        }

        $currencyRepository = (new CurrencyRepository())->getQuoteCurrencies();
        $now = \Carbon\Carbon::now();

        return Inertia::render('MarketLite/Market', [
            'fee' => Setting::get('trade.taker_fee', INITIAL_TRADE_TAKER_FEE),

            /*
             * 原本的行情资源。
             */
            'market' => new MarketResource($market),

            /*
             * 单独传给 Lite 前端。
             */
            'marketId' => (int) $market->id,
            'marketName' => $market->name,
            'stockAsset' => collect(app(\App\Services\Market\StockCatalog::class)->assets())->firstWhere('symbol', $market->baseCurrency->symbol),

            'quotes' => $currencyRepository,
            'futures' => false,
            'chart' => $chart,
            'isMobile' => is_mobile_instance(),
            'serverTime' => $now->toIso8601String(),
        ]);
    }

    private function getFuturesVipLeverageMap(): array
    {
        return [
            1 => 5,
            2 => 5,
            3 => 5,
            4 => 5,
            5 => 25,
            6 => 50,
            7 => 75,
            8 => 125,
        ];
    }

    private function parseFuturesVipLevel($value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }

        if (is_numeric($value)) {
            $level = (int) $value;
        } else {
            preg_match('/\d+/', (string) $value, $matches);
            $level = isset($matches[0]) ? (int) $matches[0] : 1;
        }

        if ($level < 1) {
            return 1;
        }

        if ($level > 8) {
            return 8;
        }

        return $level;
    }

    private function getCurrentUserFuturesVipLevel(): int
    {
        $user = auth()->user();

        if (!$user) {
            return 1;
        }

        $candidates = [
            $user->vip ?? null,
            $user->vip_level ?? null,
            $user->current_vip_level ?? null,
            $user->vip_grade ?? null,
            $user->member_level ?? null,
            $user->membership_level ?? null,
            $user->account_level ?? null,
            $user->level ?? null,
            $user->rank ?? null,
            $user->grade ?? null,
        ];

        foreach ($candidates as $value) {
            $level = $this->parseFuturesVipLevel($value);

            if ($level >= 1 && $level <= 8) {
                return $level;
            }
        }

        return 1;
    }

    private function getCurrentUserMaxFuturesLeverage(): int
    {
        $vipLevel = $this->getCurrentUserFuturesVipLevel();
        $map = $this->getFuturesVipLeverageMap();

        return (int) ($map[$vipLevel] ?? 5);
    }

    public function futuresShow(Market $market)
    {
        $market = (new MarketService())->getMarket($market->id);

        if (!$market) {
            throw new ModelNotFoundException();
        }

        $currencyRepository = (new CurrencyRepository())->getQuoteCurrencies();

        $fundingRate = Setting::get('futures.funding_fee_rate', '0.01');
        $fundingIntervalHours = (int) Setting::get('futures.funding_fee_interval_hours', 8);

        if ($fundingIntervalHours <= 0) {
            $fundingIntervalHours = 8;
        }

        /*
         * 当前服务器时间。
         * 前端进入页面时用这个时间和 K 线删除时间接口返回值做比较。
         */
        $now = \Carbon\Carbon::now();

        $currentHour = $now->hour;
        $hoursUntilNext = $fundingIntervalHours - ($currentHour % $fundingIntervalHours);

        if ($hoursUntilNext == $fundingIntervalHours) {
            $hoursUntilNext = 0;
        }

        $nextFundingTime = $now->copy()
            ->addHours($hoursUntilNext ?: $fundingIntervalHours)
            ->startOfHour()
            ->setMinute(0)
            ->setSecond(0);

        $userVipLevel = $this->getCurrentUserFuturesVipLevel();
        $maxLeverage = $this->getCurrentUserMaxFuturesLeverage();

        return Inertia::render('Market/Market', [
            /*
             * 原本的行情资源。
             */
            'market' => new MarketResource($market),

            /*
             * 单独传给前端，避免 MarketResource 没有 id。
             */
            'marketId' => (int) $market->id,
            'marketName' => $market->name,

            'quotes' => $currencyRepository,
            'futures' => true,
            'fee' => Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE),
            'makerFee' => Setting::get('futures.maker_fee', INITIAL_FUTURES_MAKER_FEE),
            'fundingRate' => $fundingRate,
            'fundingIntervalHours' => $fundingIntervalHours,
            'nextFundingTime' => $nextFundingTime->toIso8601String(),

            /*
             * 页面进入时的服务器时间。
             */
            'serverTime' => $now->toIso8601String(),

            /*
             * VIP 最大杠杆限制。
             */
            'userVipLevel' => $userVipLevel,
            'futuresUserVipLevel' => $userVipLevel,
            'maxLeverage' => $maxLeverage,
            'futuresMaxLeverage' => $maxLeverage,
            'vipLeverageMap' => $this->getFuturesVipLeverageMap(),
        ]);
    }

    public function futuresShowLite(Market $market)
    {
        $market = (new MarketService())->getMarket($market->id);

        if (!$market) {
            throw new ModelNotFoundException();
        }

        $currencyRepository = (new CurrencyRepository())->getQuoteCurrencies();

        $fundingRate = Setting::get('futures.funding_fee_rate', '0.01');
        $fundingIntervalHours = (int) Setting::get('futures.funding_fee_interval_hours', 8);

        if ($fundingIntervalHours <= 0) {
            $fundingIntervalHours = 8;
        }

        /*
         * 当前服务器时间。
         * Lite 页面进入时也要带给前端。
         */
        $now = \Carbon\Carbon::now();

        $currentHour = $now->hour;
        $hoursUntilNext = $fundingIntervalHours - ($currentHour % $fundingIntervalHours);

        if ($hoursUntilNext == $fundingIntervalHours) {
            $hoursUntilNext = 0;
        }

        $nextFundingTime = $now->copy()
            ->addHours($hoursUntilNext ?: $fundingIntervalHours)
            ->startOfHour()
            ->setMinute(0)
            ->setSecond(0);

        $userVipLevel = $this->getCurrentUserFuturesVipLevel();
        $maxLeverage = $this->getCurrentUserMaxFuturesLeverage();

        return Inertia::render('MarketLite/Market', [
            /*
             * 原本的行情资源。
             */
            'market' => new MarketResource($market),

            /*
             * 单独传给 Lite 前端。
             */
            'marketId' => (int) $market->id,
            'marketName' => $market->name,

            'quotes' => $currencyRepository,
            'futures' => true,
            'fee' => Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE),
            'makerFee' => Setting::get('futures.maker_fee', INITIAL_FUTURES_MAKER_FEE),
            'fundingRate' => $fundingRate,
            'fundingIntervalHours' => $fundingIntervalHours,
            'nextFundingTime' => $nextFundingTime->toIso8601String(),

            /*
             * 页面进入时的服务器时间。
             */
            'serverTime' => $now->toIso8601String(),

            /*
             * VIP 最大杠杆限制。
             */
            'userVipLevel' => $userVipLevel,
            'futuresUserVipLevel' => $userVipLevel,
            'maxLeverage' => $maxLeverage,
            'futuresMaxLeverage' => $maxLeverage,
            'vipLeverageMap' => $this->getFuturesVipLeverageMap(),
        ]);
    }

    public function klineDeleteTime(\App\Models\Market\Market $market)
    {
        $marketId = (int) $market->id;
        $deletedAt = \Illuminate\Support\Facades\Cache::get('market_kline_deleted_at_' . $marketId);
        $adjustedAt = \Illuminate\Support\Facades\Cache::get('market_kline_adjusted_at_' . $marketId);
        $changedAt = $adjustedAt ?: $deletedAt;

        if ($deletedAt && $adjustedAt) {
            $changedAt = strtotime((string) $adjustedAt) >= strtotime((string) $deletedAt)
                ? $adjustedAt
                : $deletedAt;
        }

        $quotePrecision = (int) ($market->quote_precision ?? 8);
        $basePrecision = (int) ($market->base_precision ?? 8);
        $runtimeConfig = \Illuminate\Support\Facades\Cache::get('market_kline_runtime_config_' . $marketId, []);
        $runtimeLast = is_array($runtimeConfig) ? (float) ($runtimeConfig['last'] ?? 0) : 0;
        $last = $runtimeLast > 0 ? $runtimeLast : (float) market_get_stats($marketId, 'last');
        // A current K-line price is not a rolling 24-hour high or low.
        // Use the same ticker statistics as the initial market resource.
        $high = (float) market_get_stats($marketId, 'high');
        $low = (float) market_get_stats($marketId, 'low');

        return response()->json([
            'market_id' => $marketId,
            'server_time' => now()->toIso8601String(),
            'deleted_at' => $deletedAt,
            'adjusted_at' => $adjustedAt,
            'changed_at' => $changedAt,
            'market' => [
                'name' => $market->name,
                'trading_session' => app(\App\Services\Market\HongKongPriceProduct::class)->marketSession($market),
                'last' => math_formatter($last, $quotePrecision),
                'high' => $high > 0 ? math_formatter($high, $quotePrecision) : null,
                'low' => $low > 0 ? math_formatter($low, $quotePrecision) : null,
                'volume' => math_formatter(market_get_stats($marketId, 'volume'), $basePrecision),
                'qVolume' => math_formatter(market_get_stats($marketId, 'qVolume'), $quotePrecision),
                'change' => math_formatter((float) (market_get_stats($marketId, 'change') ?? 0), 2),
                'updated_at' => \App\Services\Market\HongKongPriceProduct::isMarket($market) ? now()->toIso8601String() : ($changedAt ?: now()->toIso8601String()),
            ],
        ]);
    }

    public function optionsShow(Market $market)
    {
        $market = (new MarketService())->getMarket($market->id);

        if (!$market) {
            throw new ModelNotFoundException();
        }

        $currencyRepository = (new CurrencyRepository())->getQuoteCurrencies();

        return Inertia::render('Market/Options', [
            'market' => new MarketResource($market),
            'quotes' => $currencyRepository,
        ]);
    }

    public function optionsShowLite(Market $market)
    {
        $market = (new MarketService())->getMarket($market->id);

        if (!$market) {
            throw new ModelNotFoundException();
        }

        $currencyRepository = (new CurrencyRepository())->getQuoteCurrencies();

        return Inertia::render('MarketLite/Options', [
            'market' => new MarketResource($market),
            'quotes' => $currencyRepository,
        ]);
    }

    public function swap()
    {
        $currencyRepository = new CurrencyRepository();

        $defaultMarket = setting('general.swap_market', false);

        if ($defaultMarket) {
            $defaultPair = Market::whereName($defaultMarket)->first();
        } else {
            $defaultPair = Market::first();
        }

        $currencyCollection = $currencyRepository->all(false);

        $currencies = [];

        $defaultBasePair = '';
        $defaultQuotePair = '';

        foreach ($currencyCollection as $key => $currency) {
            /*
             * 这里按照币种自身精度返回给前端。
             * 不同项目字段名可能不一样，所以做了几个兼容：
             * precision / decimals / decimal_places
             */
            $precision = $currency->getAttribute('precision');

            if ($precision === null) {
                $precision = $currency->getAttribute('decimals');
            }

            if ($precision === null) {
                $precision = $currency->getAttribute('decimal_places');
            }

            if ($precision === null) {
                $precision = 8;
            }

            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path,
                'precision' => (int) $precision,
            ];
        }

        if ($defaultPair) {
            $defaultBasePair = $defaultPair->base_currency_id;
            $defaultQuotePair = $defaultPair->quote_currency_id;
        }

        return Inertia::render('Market/Swap', [
            'currencies' => $currencies,
            'defaultBasePair' => $defaultBasePair,
            'defaultQuotePair' => $defaultQuotePair,
        ]);
    }

    #[ExcludeRouteFromDocs]
    public function exchangeRate()
    {
        $base = request()->get('base');
        $quote = request()->get('quote');

        $market = Market::where(function ($query) use ($base, $quote) {
            $query->where(function ($query) use ($base, $quote) {
                $query->where('base_currency_id', $base);
                $query->where('quote_currency_id', $quote);
            })->orWhere(function ($query) use ($base, $quote) {
                $query->where('base_currency_id', $quote);
                $query->where('quote_currency_id', $base);
            });
        })->active()->first();

        if (!$market) {
            return response()->json([
                'success' => false,
                'message' => __('Pair is not available'),
            ]);
        }

        if (!$market->trade_status || !$market->buy_order_status || !$market->buy_order_status) {
            return response()->json([
                'success' => false,
                'message' => __('Pair is not tradable'),
            ]);
        }

        $rate = market_get_stats($market->id, 'last');

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => __('Not enough liquidity'),
            ]);
        }

        if ($base == $market->quote_currency_id) {
            $rate = math_divide(1, $rate);
        }

        return response()->json([
            'success' => true,
            'rate' => math_formatter($rate, 8),
            'market' => $market->name,
            'basePrecision' => $market->base_precision,
            'quotePrecision' => $market->quote_precision,
            'marketBase' => $market->base_currency_id,
        ]);
    }
}
