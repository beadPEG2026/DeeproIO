<?php

namespace App\Http\Controllers\Web\Client;

use App\Services\Market\MarketPriceMultiplier;
use App\Http\Controllers\Controller;
use App\Models\Market\Market;
use App\Repositories\Market\MarketRepository;
use App\Services\Chart\ExternalCandleService;
use App\Services\Market\MarketService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChartController extends Controller
{
    protected const KLINE_ADJUSTMENT_ANCHOR_SECONDS = 86400;

    protected $candles = [];

    protected $klineRuntimeConfigCache = [];

    protected $klineDeletedAtCache = [];

    protected $klineAdjustedAtCache = [];

    protected $klineAdjustedPriceCache = [];

    protected $klineOverrideFileCache = [];

    protected ExternalCandleService $externalCandleService;

    public function __construct(ExternalCandleService $externalCandleService)
    {
        $this->externalCandleService = $externalCandleService;
    }

    public function index($symbol, $route, $theme = 'dark')
    {
        $market = $this->resolveMarketForChartSymbol($symbol);

        $local=$market && \App\Services\Market\HongKongPriceProduct::isMarket($market) && request('feed')==='trades';
        $resolution = $local ? '15' : ($market && \App\Services\Market\HongKongPriceProduct::isMarket($market) ? config('hk-price-products.chart_default_resolution','1D') : ($market?->chart_default_resolution ?? '1D'));

        return view('chart.index', [
            'symbol' => $symbol,
            'route' => $route,
            'theme' => $theme,
            'resolution' => $resolution,
            'feedUrl' => route('chart.candles').($local?'/trades':''),
            'chartBootstrap' => [
                'config' => $market && \App\Services\Market\HongKongPriceProduct::isMarket($market) ? array_replace(MARKET_CHART_CONFIGS,['supported_resolutions'=>$local?\App\Services\Chart\TradeCandles::RESOLUTIONS:config('hk-price-products.chart_resolutions',['1D'])]) : MARKET_CHART_CONFIGS,
                'symbol' => $this->symbols(Request::create('/', 'GET', ['symbol' => $symbol,'feed'=>$local?'trades':'reference']))->getData(true),
            ],
        ]);
    }

    public function mobile($symbol, $route, $theme)
    {
        $market = $this->resolveMarketForChartSymbol($symbol);

        $local=$market && \App\Services\Market\HongKongPriceProduct::isMarket($market) && request('feed')==='trades';
        $resolution = $local ? '15' : ($market && \App\Services\Market\HongKongPriceProduct::isMarket($market) ? config('hk-price-products.chart_default_resolution','1D') : ($market?->chart_default_resolution ?? '1D'));

        return view('chart.mobile', [
            'symbol' => $symbol,
            'route' => $route,
            'theme' => $theme,
            'resolution' => $resolution,
            'feedUrl' => route('chart.candles').($local?'/trades':''),
            'chartBootstrap' => [
                'config' => $market && \App\Services\Market\HongKongPriceProduct::isMarket($market) ? array_replace(MARKET_CHART_CONFIGS,['supported_resolutions'=>$local?\App\Services\Chart\TradeCandles::RESOLUTIONS:config('hk-price-products.chart_resolutions',['1D'])]) : MARKET_CHART_CONFIGS,
                'symbol' => $this->symbols(Request::create('/', 'GET', ['symbol' => $symbol,'feed'=>$local?'trades':'reference']))->getData(true),
            ],
        ]);
    }

    public function symbols(Request $request)
    {
        $market = $this->resolveMarketForChartSymbol($request->get('symbol'));

        if (!$market) {
            return response()->json([]);
        }

        $platformSymbol = (string) $market->name;
        $platformExchange = config('app.name');

        if (\App\Services\Market\HongKongPriceProduct::isMarket($market) && ($request->get('feed')==='trades' || $request->route('chart_feed')==='trades')) {
            return response()->json(['exchange'=>'Deepro','listed_exchange'=>'Deepro','ticker'=>$platformSymbol,
                'name'=>$platformSymbol,'symbol'=>$platformSymbol,'description'=>$platformSymbol.' · USDT','currency_code'=>'USDT',
                'timezone'=>'Etc/UTC','session'=>'24x7','type'=>'stock','pricescale'=>pow(10,$market->quote_precision),
                'minmov'=>1,'minmov2'=>0,'has_intraday'=>true,'has_daily'=>true,'has_empty_bars'=>false,
                'supported_resolutions'=>\App\Services\Chart\TradeCandles::RESOLUTIONS,'intraday_multipliers'=>['1','5','15','60','240'],
                'visible_plots_set'=>'ohlcv']);
        }

        if (\App\Services\Market\HongKongPriceProduct::isMarket($market)) {
            $definition = config('hk-price-products.assets.'.$market->baseCurrency->symbol, []);
            return response()->json(['exchange'=>'HKEX','listed_exchange'=>'HKEX',
                'ticker'=>$platformSymbol,'name'=>$platformSymbol,'symbol'=>$platformSymbol,
                'description'=>($definition['ticker'] ?? $market->baseCurrency->symbol).' · USDT', 'currency_code'=>'USDT',
                'timezone'=>'Asia/Hong_Kong','session'=>'0930-1200,1300-1610:23456','data_status'=>'delayed_streaming',
                'session_holidays'=>implode(',', array_map(fn($d)=>str_replace('-','',$d), config('hk-price-products.holidays',[]))),
                'corrections'=>'0930-1200:'.implode(',', array_map(fn($d)=>str_replace('-','',$d), config('hk-price-products.half_days',[]))),
                'type'=>'stock','pricescale'=>pow(10,max(6,$market->quote_precision)),'minmov'=>1,'minmov2'=>0,
                'has_intraday'=>count(array_diff(config('hk-price-products.chart_resolutions',['1D']),['1D']))>0,'has_daily'=>true,'has_empty_bars'=>false,
                'supported_resolutions'=>config('hk-price-products.chart_resolutions',['1D']),'intraday_multipliers'=>array_values(array_diff(config('hk-price-products.chart_resolutions',['1D']),['1D'])),
                'visible_plots_set'=>'ohlcv']);
        }

        if ($market->switch_chart) {
            $exchange = $market->chart_source ?? 'binance';

            $symbol = $market->chart_symbol
                ? $market->chart_symbol
                : $this->normalizeSymbol($market->name);

            $info = $this->externalCandleService->getSymbolInfo($symbol, $exchange);

            if (!is_array($info)) {
                $info = [];
            }

            /**
             * TradingView 左上角显示名称会优先读取 name / ticker / symbol / full_name / description。
             * 外部行情源返回的这些字段可能是 Binance / MEXC / Bybit 的真实交易对，
             * 所以这里全部强制覆盖成平台自己的交易对名称。
             */
            $info['name'] = $platformSymbol;
            $info['ticker'] = $platformSymbol;
            $info['symbol'] = $platformSymbol;
            $info['full_name'] = $platformSymbol;
            $info['description'] = $platformSymbol;

            /**
             * 交易所名称也显示成平台自己的名称。
             */
            $info['exchange'] = $platformExchange;
            $info['listed_exchange'] = $platformExchange;

            /**
             * 价格精度必须使用平台市场自己的精度。
             */
            $info['pricescale'] = pow(10, $market->quote_precision);
            $info['currency_code'] = $market->quoteCurrency->symbol;

            /**
             * 保底补齐 TradingView 需要的字段，避免外部源缺字段时图表异常。
             */
            $info['session'] = $info['session'] ?? '24x7';
            $info['intraday_multipliers'] = $info['intraday_multipliers'] ?? MARKET_CHART_RESOLUTION;
            $info['has_intraday'] = $info['has_intraday'] ?? true;
            $info['has_empty_bars'] = $info['has_empty_bars'] ?? true;
            unset($info['has_no_volume']);
            $info['visible_plots_set'] = 'ohlcv';
            $info['timezone'] = 'Etc/UTC';
            $info['type'] = $info['type'] ?? MARKET_CHART_TYPE;
            $info['minmov'] = $info['minmov'] ?? 1;
            $info['minmov2'] = $info['minmov2'] ?? 0;

            return response()->json($info);
        }

        return response()->json([
            'exchange' => $platformExchange,
            'listed_exchange' => $platformExchange,
            'ticker' => $platformSymbol,
            'name' => $platformSymbol,
            'symbol' => $platformSymbol,
            'full_name' => $platformSymbol,
            'description' => $platformSymbol,
            'intraday_multipliers' => MARKET_CHART_RESOLUTION,
            'visible_plots_set' => 'ohlcv',
            'timezone' => 'Etc/UTC',
            'minmov' => 1,
            'minmov2' => 0,
            'has_intraday' => true,
            'has_empty_bars' => true,
            'type' => MARKET_CHART_TYPE,
            'pricescale' => pow(10, $market->quote_precision),
            'session' => '24x7',
        ]);
    }

    public function history(Request $request)
    {
        $market = $this->resolveMarketForChartSymbol($request->get('symbol'));

        if (!$market) {
            return response()->json(['s' => 'no_data']);
        }

        $input = $request->validate(['countback' => 'sometimes|integer|min:1|max:5000', 'from'=>'required|integer|min:0', 'to'=>'required|integer|gt:from', 'resolution'=>'required|string|max:4']);
        $request->merge(['resolution'=>$this->normalizeResolutionKey($input['resolution'])]);
        if (!\App\Services\Chart\HistoryWindow::valid((int)$input['from'],(int)$input['to'],$request->input('resolution'))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['from'=>__('Chart range exceeds 5000 bars or resolution is unsupported.')]);
        }
        if (isset($input['countback'])) {
            $range = $request->validate(['from' => 'required|integer|min:0', 'to' => 'required|integer|gt:from', 'resolution' => 'required|string|max:4']);
            $request->merge(['from' => \App\Services\Chart\HistoryWindow::start(
                (int) $range['from'], (int) $range['to'], $range['resolution'], (int) $input['countback'], time()
            )]);
        }

        if ($request->route('chart_feed')==='trades' || $request->get('feed')==='trades') {
            abort_unless(\App\Services\Market\HongKongPriceProduct::isMarket($market),404);
            $input=$request->validate(['from'=>'required|integer|min:0','to'=>'required|integer|gt:from','resolution'=>'required|in:1,5,15,60,240,D,1D']);
            return response()->json(app(\App\Services\Chart\TradeCandles::class)->history($market,(int)$input['from'],(int)$input['to'],$input['resolution'],$request->has('countback')?(int)$request->input('countback'):null));
        }

        if (\App\Services\Market\StockReferenceData::supports($market->name)) {
            $input=$request->validate(['from'=>'required|integer|min:0','to'=>'required|integer|min:0','resolution'=>'required|in:1,5,15,60,240,D,1D']);
            try {
                $history=app(\App\Services\Market\StockReferenceData::class)->history($market,(int)$input['from'],(int)$input['to'],$input['resolution'],$request->has('countback')?(int)$request->input('countback'):null);
                if (\App\Services\Market\HongKongPriceProduct::isMarket($market)) $history=app(\App\Services\Market\DisplayExchangeRates::class)->convertHongKongHistory($history,(string)config('hk-price-products.assets.'.$market->baseCurrency->symbol.'.unitRatio','1'));
                return response()->json($history);
            }
            catch (\Throwable $e) {return response()->json(['s'=>'error','errmsg'=>'币股行情暂不可用，请稍后重试。'],503);}
        }

        if ($market->switch_chart) {
            return $this->getExternalHistory($request, $market);
        }

        return $this->getInternalHistory($request, $market);
    }

    protected function resolveMarketForChartSymbol($symbol)
    {
        $symbol = trim((string) $symbol);

        if ($symbol === '') {
            return null;
        }

        $marketRepository = new MarketRepository();
        $market = $marketRepository->get($symbol);

        if ($market) {
            return $market;
        }

        $market = $marketRepository->get($symbol, false, false, true);

        if ($market) {
            return $market;
        }

        $normalized = $this->normalizeSymbol($symbol);

        if ($normalized === '') {
            return null;
        }

        return Market::query()
            ->active()
            ->where(function ($query) use ($normalized) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(name), '-', ''), '_', ''), '/', ''), ' ', '') = ?",
                    [$normalized]
                )->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(chart_symbol, '')), '-', ''), '_', ''), '/', ''), ' ', '') = ?",
                    [$normalized]
                );
            })
            ->first();
    }

    protected function getExternalHistory(Request $request, $market)
    {
        $from = intval($request->get('from'));
        $to = intval($request->get('to'));

        $requestResolution = $request->get('resolution', '5');
        $resolution = $this->normalizeResolutionKey($requestResolution);
        $exchange = $market->chart_source ?? 'binance';

        $symbol = $market->chart_symbol
            ? $market->chart_symbol
            : $this->normalizeSymbol($market->name);

        $bypassExternalCache = $this->shouldBypassExternalCandleCache($market);

        $candles = $this->externalCandleService->getCandles(
            $symbol,
            $from,
            $to,
            $requestResolution,
            $exchange,
            $bypassExternalCache
        );
        if (\App\Services\Market\StablecoinOrientation::inverse($market)) {
            try { $candles = \App\Services\Market\StablecoinOrientation::candles($candles); }
            catch (\InvalidArgumentException $e) { $candles = ['s'=>'error','errmsg'=>'Invalid reference price']; }
            // Preserve the provider's true OHLC and quote volume after inversion.
            // Legacy open-chain/wick/custom-price transforms are not reference data.
            return response()->json($candles);
        }
        unset($candles['qv']);

        if (
            empty($candles) ||
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return response()->json($candles);
        }

        $count = count($candles['c']);

        if ($count <= 0) {
            return response()->json($candles);
        }

        $precision = (int) $market->quote_precision;

        /**
         * 先整理外部原始 K 线自身 OHLC 关系。
         */
        $candles = $this->normalizeRawCandles($candles, $precision);

        /**
         * 百分比偏移状态。
         *
         * 重点：参数调整以后，不再把所有历史重新按新百分比抬高 / 压低。
         * 只从 changed_at 所在的当前 K 线开始使用新百分比。
         * 这样图上会出现一根绿线拉上去，或者一根红线跌下来，之前历史保持原样。
         */
        $percentCustomActive = $this->isPercentCustomLiquidityActiveForKline($market);
        $percentState = $this->syncCustomLiquidityPercentState($market, $percentCustomActive);
        $percentChangedAt = isset($percentState['changed_at']) ? (int) $percentState['changed_at'] : Carbon::now()->timestamp;

        if ($percentCustomActive) {
            $candles = $this->applyPercentCustomLiquidityToCandles(
                $candles,
                $market,
                $precision,
                $resolution,
                $percentChangedAt
            );
        }

        /**
         * 再合并本地历史。
         * 有历史的 timestamp 使用历史，没历史的 timestamp 使用当前接口数据。
         */
        $candles = $this->mergeStoredCustomKlineOverridesFromFile(
            $candles,
            $market,
            $resolution,
            $precision
        );

        /*
         * bs 还没乘之前，只整理当前响应里的 open 链，不写 open/close 锁。
         * 否则最终乘完 bs 后会从锁里读回未乘 bs 的 open，出现 O=原始价、C=bs 后价格。
         */
        $candles = $this->fixCandlesOpenByPreviousClose($candles, $precision, $resolution);

        if ($percentCustomActive) {
            $this->saveCustomKlineOverridesToFile(
                $candles,
                $market,
                $resolution,
                $precision
            );
        }

        $candles['s'] = $candles['s'] ?? 'ok';

        /**
         * K线倍数直接读取 markets.bs。
         * 放在最终返回前，不写入本地 K 线缓存，避免刷新后重复乘倍数。
        */
        $candles = $this->multiplyKlinePricesForSpecialMarket($candles, $market, $precision);
        $candles = $this->normalizeKlinePriceUnitWithRuntimeLast($candles, $market, $precision, $resolution);
        $candles = $this->applyKlineAdjustmentAnchorToCandles($candles, $market, $precision, $resolution);
        $candles = $this->syncCurrentOpenCandleWithRuntimeLast($candles, $market, $precision, $resolution);
        $candles = $this->clampRecentKlineAdjustmentCandles($candles, $market, $precision, $resolution);
        $candles = $this->fixCandlesOpenByPreviousClose($candles, $precision, $resolution, $market);

        return response()->json($candles);
    }

    protected function isPercentCustomLiquidityActiveForKline($market): bool
    {
        $percent = $this->getCustomLiquidityPercent($market);
        $customLiquidityActive = $this->getCustomLiquidityActive($market);

        return $customLiquidityActive
            && abs($percent) > 0.00000001
            && $this->getCustomLiquidityMultiplier($market) > 0;
    }

    protected function getCustomLiquidityPercent($market): float
    {
        $config = $this->getKlineRuntimeConfig((int) ($market->id ?? 0));

        if (array_key_exists('bot_price_ceiling', $config)) {
            return (float) $config['bot_price_ceiling'];
        }

        return (float) ($market->bot_price_ceiling ?? 0);
    }

    protected function getCustomLiquidityActive($market): bool
    {
        $config = $this->getKlineRuntimeConfig((int) ($market->id ?? 0));

        if (array_key_exists('custom_liquidity_t', $config)) {
            return filter_var($config['custom_liquidity_t'], FILTER_VALIDATE_BOOLEAN);
        }

        return (int) ($market->custom_liquidity_t ?? 0) === 1;
    }

    protected function getCustomLiquidityMultiplier($market): float
    {
        $percent = $this->getCustomLiquidityPercent($market);
        $multiplier = 1 + ($percent / 100);

        return $multiplier > 0 ? $multiplier : 0;
    }

    protected function shouldBypassExternalCandleCache($market): bool
    {
        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0) {
            return false;
        }

        $changedAt = Cache::get($this->getMarketKlineAdjustedCacheKey($marketId));

        if (!$changedAt) {
            return false;
        }

        $changedTimestamp = strtotime((string) $changedAt);

        if ($changedTimestamp <= 0 || $this->isKlineAdjustmentClearedAfter($marketId, $changedTimestamp)) {
            return false;
        }

        return (time() - $changedTimestamp) <= self::KLINE_ADJUSTMENT_ANCHOR_SECONDS;
    }

    protected function getMarketKlineAdjustedCacheKey(int $marketId): string
    {
        return 'market_kline_adjusted_at_' . $marketId;
    }

    protected function getKlineRuntimeConfig(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        if(array_key_exists($marketId, $this->klineRuntimeConfigCache)) {
            return $this->klineRuntimeConfigCache[$marketId];
        }

        $config = Cache::get('market_kline_runtime_config_' . $marketId, []);

        return $this->klineRuntimeConfigCache[$marketId] = is_array($config) ? $config : [];
    }

    protected function syncCustomLiquidityPercentState($market, bool $active): array
    {
        $currentPercent = $this->getCustomLiquidityPercent($market);
        $state = $this->readKlinePercentStateFile($market);
        $nowTs = Carbon::now()->timestamp;

        $oldPercent = array_key_exists('percent', $state) ? (float) $state['percent'] : null;
        $oldActive = isset($state['active']) ? (bool) $state['active'] : false;

        /**
         * 未开启百分比偏移时，只记录当前状态。
         * 下次重新开启，即使百分比相同，也会从重新开启的时间开始衔接。
         */
        if (!$active) {
            if ($oldActive !== false || $oldPercent === null || abs($oldPercent - $currentPercent) > 0.00000001) {
                $state = [
                    'active' => false,
                    'percent' => $currentPercent,
                    'changed_at' => $nowTs,
                    'updated_at' => now()->toDateTimeString(),
                ];

                $this->writeKlinePercentStateFile($market, $state);
            }

            if (empty($state)) {
                $state = [
                    'active' => false,
                    'percent' => $currentPercent,
                    'changed_at' => $nowTs,
                    'updated_at' => now()->toDateTimeString(),
                ];
            }

            return $state;
        }

        /**
         * 第一次开启，或者百分比发生变化：记录新的变动时间。
         * 之后只从这个时间所在的 K 线开始应用新百分比。
         */
        if ($oldPercent === null || !$oldActive || abs($oldPercent - $currentPercent) > 0.00000001) {
            $state = [
                'active' => true,
                'percent' => $currentPercent,
                'changed_at' => $nowTs,
                'updated_at' => now()->toDateTimeString(),
            ];

            $this->writeKlinePercentStateFile($market, $state);

            return $state;
        }

        if (empty($state['changed_at'])) {
            $state['changed_at'] = $nowTs;
            $state['updated_at'] = now()->toDateTimeString();
            $this->writeKlinePercentStateFile($market, $state);
        }

        return $state;
    }

    protected function getKlinePercentStateFilePath($market): string
    {
        $marketId = (int) $market->id;

        return storage_path("app/market_kline_overrides/{$marketId}/percent_state.json");
    }

    protected function readKlinePercentStateFile($market): array
    {
        $path = $this->getKlinePercentStateFilePath($market);

        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    protected function writeKlinePercentStateFile($market, array $state): void
    {
        $path = $this->getKlinePercentStateFilePath($market);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $tmpPath = $path . '.tmp';

        file_put_contents($tmpPath, $json, LOCK_EX);
        rename($tmpPath, $path);
    }

    protected function applyPercentCustomLiquidityToCandles(
        array $candles,
        $market,
        int $precision,
        $resolution = '1',
        ?int $percentChangedAt = null
    ): array {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $multiplier = $this->getCustomLiquidityMultiplier($market);

        if ($multiplier <= 0) {
            return $candles;
        }

        $resolution = $this->normalizeResolutionKey($resolution);
        $percentChangedAt = $percentChangedAt ?: 0;
        $count = count($candles['c']);

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);
            $barEndTs = $this->getCandleEndTimestamp($timestamp, $resolution);

            /**
             * 核心修复：
             * 参数调整以前的 K 线，不按新百分比整体抬高 / 压低。
             * 只有 changed_at 所在 K 线以及后面的 K 线，才使用新百分比。
             */
            if ($percentChangedAt > 0 && $barEndTs <= $percentChangedAt) {
                continue;
            }

            $open = round(((float)($candles['o'][$i] ?? 0)) * $multiplier, $precision);
            $high = round(((float)($candles['h'][$i] ?? 0)) * $multiplier, $precision);
            $low = round(((float)($candles['l'][$i] ?? 0)) * $multiplier, $precision);
            $close = round(((float)($candles['c'][$i] ?? 0)) * $multiplier, $precision);

            if ($open <= 0 && $close > 0) {
                $open = $close;
            }

            if ($close <= 0 && $open > 0) {
                $close = $open;
            }

            if ($open <= 0 || $close <= 0) {
                continue;
            }

            $candles['o'][$i] = $open;
            $candles['c'][$i] = $close;
            $candles['h'][$i] = round(max($high, $open, $close), $precision);
            $candles['l'][$i] = round(min($low, $open, $close), $precision);
        }

        return $candles;
    }

    /**
     * 保存已经收盘的 K 线到本地历史文件。
     * 不再使用 start / stop，百分比偏移只看 custom_liquidity_t 与 bot_price_ceiling。
     */
    protected function saveCustomKlineOverridesToFile(
        array $candles,
        $market,
        $resolution,
        int $precision
    ): void {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return;
        }

        $count = count($candles['c']);

        if ($count <= 0) {
            return;
        }

        $resolution = $this->normalizeResolutionKey($resolution);
        $nowTs = Carbon::now()->timestamp;
        $stored = $this->readKlineOverrideFile($market, $resolution);

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);
            $barEndTs = $this->getCandleEndTimestamp($timestamp, $resolution);

            /**
             * 当前未收盘 K 线不保存，避免刷新后当前线被冻结。
             */
            if ($barEndTs > $nowTs) {
                continue;
            }

            $volume = null;

            if (!empty($candles['v']) && isset($candles['v'][$i])) {
                $volume = (string) $candles['v'][$i];
            }

            $existing = isset($stored[(string) $timestamp]) && is_array($stored[(string) $timestamp])
                ? $stored[(string) $timestamp]
                : [];

            if (!empty($existing['manual_current'])) {
                continue;
            }

            $stored[(string) $timestamp] = [
                't' => $timestamp,
                'o' => number_format((float) $candles['o'][$i], $precision, '.', ''),
                'h' => number_format((float) $candles['h'][$i], $precision, '.', ''),
                'l' => number_format((float) $candles['l'][$i], $precision, '.', ''),
                'c' => number_format((float) $candles['c'][$i], $precision, '.', ''),
                'v' => $volume,
                'percent' => $this->getCustomLiquidityPercent($market),
                'manual_current' => !empty($existing['manual_current']),
                'updated_at' => now()->toDateTimeString(),
            ];
        }

        ksort($stored);

        if (count($stored) > 10000) {
            $stored = array_slice($stored, -10000, null, true);
        }

        $this->writeKlineOverrideFile($market, $resolution, $stored);
    }

    /**
     * 合并本地历史覆盖。
     *
     * 规则：
     * 1. 当前周期自己的历史文件优先作为基础。
     * 2. 大于 1m 的周期必须合并 1m 历史的 high / low。
     * 3. 当前未收盘 K 线：
     *    - 不使用当前周期自己的历史，避免冻结当前 close。
     *    - 但允许合并 1m 已收盘历史的 high / low。
     *
     * 这样如果 1m 出现最低价 70000，
     * 5m / 15m / 1h / 1d 当前这根 K 线也会包含 70000。
     */
    protected function mergeStoredCustomKlineOverridesFromFile(
        array $candles,
        $market,
        $resolution,
        int $precision
    ): array {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $count = count($candles['c']);

        if ($count <= 0) {
            return $candles;
        }

        $resolution = $this->normalizeResolutionKey($resolution);

        $stored = $this->buildStoredKlineOverrideMapForResolution(
            $market,
            $resolution,
            $precision
        );

        if (empty($stored)) {
            return $this->fixCandlesOpenByPreviousClose($candles, $precision, $resolution);
        }

        $nowTs = Carbon::now()->timestamp;

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);
            $barEndTs = $this->getCandleEndTimestamp($timestamp, $resolution);
            $key = (string) $timestamp;

            if (empty($stored[$key])) {
                continue;
            }

            $item = $stored[$key];
            $fromOneMinute = !empty($item['_from_1m']);
            $manualCurrent = !empty($item['manual_current']) || !empty($item['_has_manual_current']);

            if ($barEndTs > $nowTs && !$fromOneMinute && !$manualCurrent) {
                continue;
            }

            $isCurrentOpenCandle = $this->isCurrentOpenCandle($timestamp, $resolution);

            if (!$fromOneMinute) {
                $storedOpen = round((float) $item['o'], $precision);
                $storedHigh = round((float) ($item['h'] ?? $item['o']), $precision);
                $storedLow = round((float) ($item['l'] ?? $item['o']), $precision);
                $storedClose = round((float) $item['c'], $precision);

                if ($isCurrentOpenCandle) {
                    /*
                     * 当前未收盘 K 线的 open 不能跟随手动调价记录变化。
                     * 调价只应该改变 close/high/low，否则频繁调价会把当前根拉成异常长柱。
                     */
                    $open = round((float) ($candles['o'][$i] ?? $storedOpen), $precision);

                    if ($open <= 0) {
                        $open = $storedOpen;
                    }

                    $candles['o'][$i] = $open;
                    $candles['h'][$i] = round(max($storedHigh, $open, $storedClose), $precision);
                    $candles['l'][$i] = round(min($storedLow, $open, $storedClose), $precision);
                    $candles['c'][$i] = $storedClose;
                } else {
                    $candles['o'][$i] = $storedOpen;
                    $candles['h'][$i] = round(max($storedHigh, $storedOpen, $storedClose), $precision);
                    $candles['l'][$i] = round(min($storedLow, $storedOpen, $storedClose), $precision);
                    $candles['c'][$i] = $storedClose;
                }

                if (!empty($candles['v']) && isset($item['v']) && $item['v'] !== null) {
                    $candles['v'][$i] = (float) $item['v'];
                }

                continue;
            }

            $externalOpen = isset($item['_base_o'])
                ? round((float) $item['_base_o'], $precision)
                : round((float) $candles['o'][$i], $precision);

            $externalHigh = isset($item['_base_h'])
                ? round((float) $item['_base_h'], $precision)
                : round((float) $candles['h'][$i], $precision);

            $externalLow = isset($item['_base_l'])
                ? round((float) $item['_base_l'], $precision)
                : round((float) $candles['l'][$i], $precision);

            $externalClose = isset($item['_base_c'])
                ? round((float) $item['_base_c'], $precision)
                : round((float) $candles['c'][$i], $precision);

            $historyOpen = round((float) $item['o'], $precision);
            $historyHigh = round((float) $item['h'], $precision);
            $historyLow = round((float) $item['l'], $precision);
            $historyClose = round((float) $item['c'], $precision);

            $firstHistoryTs = isset($item['_first_t']) ? (int) $item['_first_t'] : $timestamp;
            $lastHistoryTs = isset($item['_last_t']) ? (int) $item['_last_t'] : $timestamp;

            $open = $externalOpen;
            $close = $externalClose;

            if (!$isCurrentOpenCandle && $firstHistoryTs <= $timestamp) {
                $open = $historyOpen;
            }

            if ($manualCurrent || $lastHistoryTs >= ($barEndTs - 60)) {
                $close = $historyClose;
            }

            if ($isCurrentOpenCandle) {
                $high = max($historyHigh, $open, $close);
                $low = min($historyLow, $open, $close);
            } else {
                $high = max($externalHigh, $historyHigh, $open, $close);
                $low = min($externalLow, $historyLow, $open, $close);
            }

            $candles['o'][$i] = round($open, $precision);
            $candles['h'][$i] = round($high, $precision);
            $candles['l'][$i] = round($low, $precision);
            $candles['c'][$i] = round($close, $precision);

            if (!empty($candles['v']) && isset($item['v']) && $item['v'] !== null) {
                $candles['v'][$i] = (float) $item['v'];
            }
        }

        /*
         * 这里仍处于 bs 之前，不能写 open/close 锁。
         */
        return $this->fixCandlesOpenByPreviousClose($candles, $precision, $resolution);
    }

    protected function buildStoredKlineOverrideMapForResolution(
        $market,
        $resolution,
        int $precision
    ): array {
        $resolutionKey = $this->normalizeResolutionKey($resolution);
        $resolutionSeconds = $this->resolutionToSeconds($resolutionKey);

        $directStored = $this->normalizeStoredOverrideRows(
            $this->readKlineOverrideFile($market, $resolutionKey),
            $precision
        );

        if ($resolutionSeconds <= 60) {
            return $directStored;
        }

        $fromOneMinute = $this->aggregateOneMinuteOverridesToResolution(
            $market,
            $resolutionKey,
            $precision
        );

        if (empty($fromOneMinute)) {
            foreach ($directStored as $timestamp => $row) {
                $directStored[(string) $timestamp]['_from_1m'] = false;
            }

            ksort($directStored);

            return $directStored;
        }

        $result = [];

        foreach ($directStored as $timestamp => $row) {
            $row['_from_1m'] = false;
            $result[(string) $timestamp] = $row;
        }

        foreach ($fromOneMinute as $timestamp => $row) {
            $key = (string) $timestamp;

            if (!empty($result[$key])) {
                $base = $result[$key];

                $row['_base_o'] = $base['o'] ?? $row['o'];
                $row['_base_h'] = $base['h'] ?? $row['h'];
                $row['_base_l'] = $base['l'] ?? $row['l'];
                $row['_base_c'] = $base['c'] ?? $row['c'];

                $row['h'] = number_format(
                    max((float) $row['h'], (float) ($base['h'] ?? $row['h'])),
                    $precision,
                    '.',
                    ''
                );

                $row['l'] = number_format(
                    min((float) $row['l'], (float) ($base['l'] ?? $row['l'])),
                    $precision,
                    '.',
                    ''
                );

                if (empty($row['v']) && !empty($base['v'])) {
                    $row['v'] = $base['v'];
                }
            }

            $row['_from_1m'] = true;
            $result[$key] = $row;
        }

        ksort($result);

        return $result;
    }

    protected function normalizeStoredOverrideRows(array $stored, int $precision): array
    {
        if (empty($stored)) {
            return [];
        }

        $result = [];

        foreach ($stored as $item) {
            if (!empty($item['runtime_current'])) {
                continue;
            }

            if (empty($item['t']) || !isset($item['o'], $item['h'], $item['l'], $item['c'])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($item['t']);

            if ($timestamp <= 0) {
                continue;
            }

            $open = round((float) $item['o'], $precision);
            $high = round((float) $item['h'], $precision);
            $low = round((float) $item['l'], $precision);
            $close = round((float) $item['c'], $precision);

            if ($open <= 0 || $close <= 0) {
                continue;
            }

            [$high, $low] = $this->limitKlineWicks($open, $high, $low, $close, $precision, $timestamp);

            $result[(string) $timestamp] = [
                't' => $timestamp,
                'o' => number_format($open, $precision, '.', ''),
                'h' => number_format(max($high, $open, $close), $precision, '.', ''),
                'l' => number_format(min($low, $open, $close), $precision, '.', ''),
                'c' => number_format($close, $precision, '.', ''),
                'v' => $item['v'] ?? null,
                '_from_1m' => $item['_from_1m'] ?? false,
                '_first_t' => $item['_first_t'] ?? $timestamp,
                '_last_t' => $item['_last_t'] ?? $timestamp,
                'percent' => $item['percent'] ?? null,
                'manual_current' => !empty($item['manual_current']),
                'updated_at' => $item['updated_at'] ?? null,
            ];
        }

        ksort($result);

        return $result;
    }

    protected function aggregateOneMinuteOverridesToResolution(
        $market,
        $resolution,
        int $precision
    ): array {
        $resolutionKey = $this->normalizeResolutionKey($resolution);
        $resolutionSeconds = $this->resolutionToSeconds($resolutionKey);

        if ($resolutionSeconds <= 60) {
            return [];
        }

        $oneMinuteStored = $this->normalizeStoredOverrideRows(
            $this->readKlineOverrideFile($market, '1'),
            $precision
        );

        if (empty($oneMinuteStored)) {
            return [];
        }

        ksort($oneMinuteStored);

        $groups = [];

        foreach ($oneMinuteStored as $item) {
            $timestamp = $this->normalizeCandleTimestamp($item['t']);

            if ($timestamp <= 0) {
                continue;
            }

            $bucketStart = $this->getResolutionBucketStartTimestamp($timestamp, $resolutionKey);
            $bucketKey = (string) $bucketStart;

            if (empty($groups[$bucketKey])) {
                $groups[$bucketKey] = [];
            }

            $groups[$bucketKey][] = $item;
        }

        $result = [];

        foreach ($groups as $bucketStart => $items) {
            usort($items, function ($a, $b) {
                return (int) $a['t'] <=> (int) $b['t'];
            });

            if (empty($items)) {
                continue;
            }

            $first = $items[0];
            $last = $items[count($items) - 1];

            $open = round((float) $first['o'], $precision);
            $close = round((float) $last['c'], $precision);
            $high = $open;
            $low = $open;
            $volume = 0;
            $hasVolume = false;

            foreach ($items as $item) {
                $itemOpen = round((float) $item['o'], $precision);
                $itemHigh = round((float) $item['h'], $precision);
                $itemLow = round((float) $item['l'], $precision);
                $itemClose = round((float) $item['c'], $precision);

                $high = max($high, $itemOpen, $itemHigh, $itemClose);
                $low = min($low, $itemOpen, $itemLow, $itemClose);

                if (isset($item['v']) && $item['v'] !== null && $item['v'] !== '') {
                    $volume += (float) $item['v'];
                    $hasVolume = true;
                }
            }

            $hasManualCurrent = collect($items)->contains(function ($item) {
                return !empty($item['manual_current']);
            });

            if ($open <= 0 || $close <= 0) {
                continue;
            }

            $result[(string) $bucketStart] = [
                't' => (int) $bucketStart,
                'o' => number_format($open, $precision, '.', ''),
                'h' => number_format(max($high, $open, $close), $precision, '.', ''),
                'l' => number_format(min($low, $open, $close), $precision, '.', ''),
                'c' => number_format($close, $precision, '.', ''),
                'v' => $hasVolume ? (string) $volume : null,
                '_from_1m' => true,
                '_first_t' => (int) $first['t'],
                '_last_t' => (int) $last['t'],
                '_has_manual_current' => $hasManualCurrent,
                'updated_at' => now()->toDateTimeString(),
            ];
        }

        ksort($result);

        return $result;
    }

    protected function getKlineOverrideFilePath($market, $resolution): string
    {
        $marketId = (int) $market->id;
        $resolutionKey = $this->normalizeResolutionKey($resolution);
        $safeResolution = preg_replace('/[^A-Za-z0-9_\-]/', '_', $resolutionKey);

        return storage_path("app/market_kline_overrides/{$marketId}/{$safeResolution}.json");
    }

    protected function readKlineOverrideFile($market, $resolution): array
    {
        $path = $this->getKlineOverrideFilePath($market, $resolution);

        if(array_key_exists($path, $this->klineOverrideFileCache)) {
            return $this->klineOverrideFileCache[$path];
        }

        if (!is_file($path)) {
            return $this->klineOverrideFileCache[$path] = [];
        }

        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            return $this->klineOverrideFileCache[$path] = [];
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return $this->klineOverrideFileCache[$path] = [];
        }

        return $this->klineOverrideFileCache[$path] = $data;
    }

    protected function writeKlineOverrideFile($market, $resolution, array $data): void
    {
        $path = $this->getKlineOverrideFilePath($market, $resolution);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        if (!is_writable($dir)) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $lockPath = $path . '.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle === false) {
            return;
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                return;
            }

            $tmpPath = $path . '.' . getmypid() . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';

            $written = @file_put_contents($tmpPath, $json, LOCK_EX);

            if ($written === false || !is_file($tmpPath)) {
                @unlink($tmpPath);
                return;
            }

            @chmod($tmpPath, 0644);

            if (!@rename($tmpPath, $path)) {
                @unlink($tmpPath);
                return;
            }

            $this->klineOverrideFileCache[$path] = $data;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    protected function normalizeRawCandles(array $candles, int $precision): array
    {
        $count = count($candles['c']);

        for ($i = 0; $i < $count; $i++) {
            $open = round((float) ($candles['o'][$i] ?? 0), $precision);
            $close = round((float) ($candles['c'][$i] ?? 0), $precision);
            $high = round((float) ($candles['h'][$i] ?? max($open, $close)), $precision);
            $low = round((float) ($candles['l'][$i] ?? min($open, $close)), $precision);

            if ($open <= 0 && $close > 0) {
                $open = $close;
            }

            if ($close <= 0 && $open > 0) {
                $close = $open;
            }

            $candles['o'][$i] = $open;
            $candles['c'][$i] = $close;
            $candles['h'][$i] = round(max($high, $open, $close), $precision);
            $candles['l'][$i] = round(min($low, $open, $close), $precision);
        }

        return $candles;
    }

    protected function fixCandlesOpenByPreviousClose(array $candles, int $precision, $resolution = null, $market = null): array
    {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $count = count($candles['c']);

        if ($count <= 0) {
            return $candles;
        }

        $rows = [];
        $hasVolume = !empty($candles['v']);

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);

            if ($timestamp <= 0) {
                continue;
            }

            $open = round((float) ($candles['o'][$i] ?? 0), $precision);
            $close = round((float) ($candles['c'][$i] ?? 0), $precision);
            $high = round((float) ($candles['h'][$i] ?? max($open, $close)), $precision);
            $low = round((float) ($candles['l'][$i] ?? min($open, $close)), $precision);

            if ($open <= 0 && $close > 0) {
                $open = $close;
            }

            if ($close <= 0 && $open > 0) {
                $close = $open;
            }

            if ($open <= 0 || $close <= 0) {
                continue;
            }

            $rows[(string) $timestamp] = [
                't' => $timestamp,
                'o' => $open,
                'h' => round(max($high, $open, $close), $precision),
                'l' => round(min($low, $open, $close), $precision),
                'c' => $close,
                'v' => $hasVolume && isset($candles['v'][$i]) ? $candles['v'][$i] : null,
            ];
        }

        if (empty($rows)) {
            return $candles;
        }

        ksort($rows);
        $rows = array_values($rows);
        $manualOverrideTimestamps = [];

        if ($market !== null && $resolution !== null) {
            $manualOverrideTimestamps = $this->getManualKlineOverrideTimestamps($market, $resolution, $precision);
        }

        for ($i = 0; $i < count($rows); $i++) {
            $open = round((float) $rows[$i]['o'], $precision);
            $close = round((float) $rows[$i]['c'], $precision);
            $high = round((float) $rows[$i]['h'], $precision);
            $low = round((float) $rows[$i]['l'], $precision);

            $isCurrentOpenCandle = $this->isCurrentOpenCandle($rows[$i]['t'], $resolution);
            $hasManualOverride = !empty($manualOverrideTimestamps[(string) $rows[$i]['t']]);
            $lockedOpen = $this->getLockedCandleOpen($market, $resolution, (int) $rows[$i]['t'], $precision);
            $lockedClose = !$hasManualOverride && !$isCurrentOpenCandle
                ? $this->getLockedCandleClose($market, $resolution, (int) $rows[$i]['t'], $precision)
                : 0;
            $lockedOhlc = $lockedOpen > 0 || $lockedClose > 0;

            if ($lockedOpen > 0) {
                $open = $lockedOpen;
            } elseif ($i > 0) {
                $prevClose = round((float) $rows[$i - 1]['c'], $precision);

                if ($prevClose > 0) {
                    $open = $market !== null
                        ? $this->lockCandleOpen($market, $resolution, (int) $rows[$i]['t'], $prevClose, $precision)
                        : $prevClose;
                    $lockedOhlc = $market !== null || $lockedOhlc;
                }
            } elseif ($market !== null && $open > 0) {
                $open = $this->lockCandleOpen($market, $resolution, (int) $rows[$i]['t'], $open, $precision);
                $lockedOhlc = true;
            }

            if ($lockedClose > 0) {
                $close = $lockedClose;
            } elseif (!$hasManualOverride && !$isCurrentOpenCandle && $market !== null) {
                $close = $this->lockCandleClose($market, $resolution, (int) $rows[$i]['t'], $close, $precision);
                $lockedOhlc = true;
            }

            if ($market !== null) {
                [$high, $low] = $this->limitKlineWicks($open, $high, $low, $close, $precision, (int) $rows[$i]['t']);
            }

            if ($high <= 0 || $low <= 0) {
                $high = max($open, $close);
                $low = min($open, $close);
            }

            $rows[$i]['o'] = $open;
            $rows[$i]['c'] = $close;
            $rows[$i]['h'] = round(max($high, $open, $close), $precision);
            $rows[$i]['l'] = round(min($low, $open, $close), $precision);
        }

        $result = [
            's' => $candles['s'] ?? 'ok',
            't' => [],
            'o' => [],
            'h' => [],
            'l' => [],
            'c' => [],
        ];

        if ($hasVolume) {
            $result['v'] = [];
        }

        foreach ($rows as $row) {
            $result['t'][] = $row['t'];
            $result['o'][] = round((float) $row['o'], $precision);
            $result['h'][] = round((float) $row['h'], $precision);
            $result['l'][] = round((float) $row['l'], $precision);
            $result['c'][] = round((float) $row['c'], $precision);

            if ($hasVolume) {
                $result['v'][] = $row['v'] ?? 0;
            }
        }

        return $result;
    }

    protected function getLockedCandleOpen($market, $resolution, int $timestamp, int $precision): float
    {
        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0 || $timestamp <= 0 || $resolution === null) {
            return 0;
        }

        $open = Cache::get($this->getCandleOpenLockCacheKey($marketId, $resolution, $timestamp, $market->bs ?? null));

        if ($this->isKlineLockCleared($marketId, $timestamp)) {
            return 0;
        }

        return $open > 0 ? round((float) $open, $precision) : 0;
    }

    protected function lockCandleOpen($market, $resolution, int $timestamp, float $open, int $precision): float
    {
        $marketId = (int) ($market->id ?? 0);
        $open = round($open, $precision);

        if ($marketId <= 0 || $timestamp <= 0 || $resolution === null || $open <= 0) {
            return $open;
        }

        $cacheKey = $this->getCandleOpenLockCacheKey($marketId, $resolution, $timestamp, $market->bs ?? null);

        if ($this->isKlineLockCleared($marketId, $timestamp)) {
            Cache::forget($cacheKey);
            Cache::put($cacheKey, $open, now()->addDays(30));

            return $open;
        }

        $lockedOpen = Cache::get($cacheKey);

        if ($lockedOpen > 0) {
            return round((float) $lockedOpen, $precision);
        }

        Cache::put($cacheKey, $open, now()->addDays(30));

        return $open;
    }

    protected function getLockedCandleClose($market, $resolution, int $timestamp, int $precision): float
    {
        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0 || $timestamp <= 0 || $resolution === null) {
            return 0;
        }

        $close = Cache::get($this->getCandleCloseLockCacheKey($marketId, $resolution, $timestamp, $market->bs ?? null));

        if ($this->isKlineLockCleared($marketId, $timestamp)) {
            return 0;
        }

        return $close > 0 ? round((float) $close, $precision) : 0;
    }

    protected function lockCandleClose($market, $resolution, int $timestamp, float $close, int $precision): float
    {
        $marketId = (int) ($market->id ?? 0);
        $close = round($close, $precision);

        if ($marketId <= 0 || $timestamp <= 0 || $resolution === null || $close <= 0) {
            return $close;
        }

        $cacheKey = $this->getCandleCloseLockCacheKey($marketId, $resolution, $timestamp, $market->bs ?? null);

        if ($this->isKlineLockCleared($marketId, $timestamp)) {
            Cache::forget($cacheKey);
            Cache::put($cacheKey, $close, now()->addDays(30));

            return $close;
        }

        $lockedClose = Cache::get($cacheKey);

        if ($lockedClose > 0) {
            return round((float) $lockedClose, $precision);
        }

        Cache::put($cacheKey, $close, now()->addDays(30));

        return $close;
    }

    protected function getCandleOpenLockCacheKey(int $marketId, $resolution, int $timestamp, $bs = null): string
    {
        $resolutionKey = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->normalizeResolutionKey($resolution));

        return 'market_kline_ohlc_lock_v4_open_' . $marketId . '_' . $resolutionKey . '_' . $timestamp
            . (MarketPriceMultiplier::resolve($bs) === 1.0 ? '' : ':bs:' . MarketPriceMultiplier::resolve($bs));
    }

    protected function getCandleCloseLockCacheKey(int $marketId, $resolution, int $timestamp, $bs = null): string
    {
        $resolutionKey = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->normalizeResolutionKey($resolution));

        return 'market_kline_ohlc_lock_v4_close_' . $marketId . '_' . $resolutionKey . '_' . $timestamp
            . (MarketPriceMultiplier::resolve($bs) === 1.0 ? '' : ':bs:' . MarketPriceMultiplier::resolve($bs));
    }

    protected function isCurrentOpenCandle(int $timestamp, $resolution = null): bool
    {
        if ($resolution === null) {
            return false;
        }

        $timestamp = $this->normalizeCandleTimestamp($timestamp);

        if ($timestamp <= 0) {
            return false;
        }

        $barEndTs = $this->getCandleEndTimestamp($timestamp, $resolution);

        return $timestamp <= time() && $barEndTs > time();
    }

    protected function syncCurrentOpenCandleWithRuntimeLast(array $candles, $market, int $precision, $resolution): array
    {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0) {
            return $candles;
        }

        $runtimeLast = (float) market_get_stats($marketId, 'last');

        $count = count($candles['t']);
        $manualOverrideTimestamps = $this->getManualKlineOverrideTimestamps($market, $resolution, $precision);

        for ($i = $count - 1; $i >= 0; $i--) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);

            if (!$this->isCurrentOpenCandle($timestamp, $resolution)) {
                continue;
            }

            $adjustmentPrice = $this->getCurrentKlineAdjustmentPriceForCandle($marketId);
            $adjustmentTimestamp = $this->getCurrentKlineAdjustmentTimestamp($marketId);
            $barEndTs = $this->getCandleEndTimestamp($timestamp, $resolution);
            $hasRecentAdjustment = $adjustmentTimestamp > 0
                && (time() - $adjustmentTimestamp) <= self::KLINE_ADJUSTMENT_ANCHOR_SECONDS;
            $hasManualOverrideForCandle = !empty($manualOverrideTimestamps[(string) $timestamp]);

            /*
             * 手动调价当前 K 线的 close 必须优先跟调价缓存同源。
             * 否则 stats / 外部行情在短时间内落后时，会把刚写入的手动 K 线又拉回旧价。
             */
            $targetLast = ($hasManualOverrideForCandle && $adjustmentPrice > 0)
                ? $adjustmentPrice
                : ($runtimeLast > 0 ? $runtimeLast : $adjustmentPrice);

            if ($targetLast <= 0) {
                continue;
            }

            $open = round((float) ($candles['o'][$i] ?? $targetLast), $precision);
            $high = round((float) ($candles['h'][$i] ?? max($open, $targetLast)), $precision);
            $low = round((float) ($candles['l'][$i] ?? min($open, $targetLast)), $precision);
            $close = round($targetLast, $precision);

            if ($open <= 0) {
                $open = $close;
            }

            if ($hasRecentAdjustment) {
                [$high, $low] = $this->limitKlineWicks($open, $high, $low, $close, $precision, $timestamp);
            }

            $candles['o'][$i] = $open;
            $candles['c'][$i] = $close;
            $candles['h'][$i] = round(max($high, $open, $close), $precision);
            $candles['l'][$i] = round(min($low, $open, $close), $precision);

            break;
        }

        return $candles;
    }

    protected function applyKlineAdjustmentAnchorToCandles(array $candles, $market, int $precision, $resolution): array
    {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0) {
            return $candles;
        }

        $adjustedAt = $this->getCachedKlineAdjustedAt($marketId);

        if (!$adjustedAt) {
            return $candles;
        }

        $adjustedTimestamp = strtotime((string) $adjustedAt);

        if (!$adjustedTimestamp || (time() - $adjustedTimestamp) > self::KLINE_ADJUSTMENT_ANCHOR_SECONDS) {
            return $candles;
        }

        if ($this->isKlineAdjustmentClearedAfter($marketId, $adjustedTimestamp)) {
            return $candles;
        }

        $anchor = Cache::get('market_kline_adjustment_anchor_' . $marketId, []);
        $anchorMatches = is_array($anchor) && ($anchor['adjusted_at'] ?? null) === (string) $adjustedAt;
        $anchorPrice = $anchorMatches ? (float) ($anchor['price'] ?? 0) : 0;
        $anchorStandardClose = $anchorMatches ? (float) ($anchor['standard_close'] ?? 0) : 0;

        if ($anchorPrice <= 0) {
            $anchorPrice = $this->getCurrentKlineAdjustmentPriceForCandle($marketId);
        }

        if ($anchorStandardClose <= 0) {
            $anchorStandardClose = $this->findCandleCloseAtTimestamp($candles, $adjustedTimestamp, $resolution);
        }

        if (
            $anchorPrice > 0 &&
            $anchorStandardClose > 0 &&
            $this->hasSeverePriceUnitMismatch($anchorStandardClose, $anchorPrice)
        ) {
            $runtimeUnitClose = $this->findCandleCloseAtTimestamp($candles, $adjustedTimestamp, $resolution);

            if ($runtimeUnitClose > 0) {
                $anchorStandardClose = $runtimeUnitClose;
            }
        }

        if ($anchorPrice <= 0 || $anchorStandardClose <= 0) {
            return $candles;
        }

        $offset = $anchorPrice - $anchorStandardClose;

        if (abs($offset) <= 0.0000000001) {
            return $candles;
        }

        $resolution = $this->normalizeResolutionKey($resolution);
        $count = count($candles['t']);
        $manualOverrideTimestamps = $this->getManualKlineOverrideTimestamps($market, $resolution, $precision);
        $changed = false;

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);

            if (!empty($manualOverrideTimestamps[(string) $timestamp])) {
                continue;
            }

            $barEndTs = $this->getCandleEndTimestamp($timestamp, $resolution);

            if ($barEndTs <= $adjustedTimestamp) {
                continue;
            }

            $isCurrentOpenCandle = $this->isCurrentOpenCandle($timestamp, $resolution);
            $sourceOpen = round((float) ($candles['o'][$i] ?? 0), $precision);
            $open = $isCurrentOpenCandle
                ? $sourceOpen
                : round($sourceOpen + $offset, $precision);
            $high = round(((float) ($candles['h'][$i] ?? 0)) + $offset, $precision);
            $low = round(((float) ($candles['l'][$i] ?? 0)) + $offset, $precision);
            $close = round(((float) ($candles['c'][$i] ?? 0)) + $offset, $precision);

            if ($open <= 0 && $close > 0) {
                $open = $close;
            }

            if ($close <= 0 && $open > 0) {
                $close = $open;
            }

            if ($open <= 0 || $close <= 0) {
                continue;
            }

            $candles['o'][$i] = $open;
            $candles['c'][$i] = $close;
            $candles['h'][$i] = round(max($high, $open, $close), $precision);
            $candles['l'][$i] = round(min($low, $open, $close), $precision);
            $changed = true;
        }

        return $changed
            ? $this->fixCandlesOpenByPreviousClose($candles, $precision, $resolution, $market)
            : $candles;
    }

    protected function clampRecentKlineAdjustmentCandles(array $candles, $market, int $precision, $resolution): array
    {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0) {
            return $candles;
        }

        $adjustedTimestamp = $this->getCurrentKlineAdjustmentTimestamp($marketId);

        if ($adjustedTimestamp <= 0 || (time() - $adjustedTimestamp) > self::KLINE_ADJUSTMENT_ANCHOR_SECONDS) {
            return $candles;
        }

        $resolution = $this->normalizeResolutionKey($resolution);
        $count = count($candles['t']);

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);

            if ($timestamp <= 0 || $this->getCandleEndTimestamp($timestamp, $resolution) <= $adjustedTimestamp) {
                continue;
            }

            $open = round((float) ($candles['o'][$i] ?? 0), $precision);
            $high = round((float) ($candles['h'][$i] ?? 0), $precision);
            $low = round((float) ($candles['l'][$i] ?? 0), $precision);
            $close = round((float) ($candles['c'][$i] ?? 0), $precision);

            if ($close <= 0) {
                continue;
            }

            if ($open <= 0) {
                $open = $close;
            }

            $candles['o'][$i] = $open;
            [$high, $low] = $this->limitKlineWicks($open, $high, $low, $close, $precision, $timestamp);
            $candles['h'][$i] = $high;
            $candles['l'][$i] = $low;
            $candles['c'][$i] = $close;
        }

        return $candles;
    }

    protected function limitKlineWicks(float $open, float $high, float $low, float $close, int $precision, int $timestamp = 0): array
    {
        if ($open <= 0 || $close <= 0) {
            return [
                round(max($open, $close), $precision),
                round(min($open, $close), $precision),
            ];
        }

        $bodyHigh = max($open, $close);
        $bodyLow = min($open, $close);
        $priceTick = $precision > 0 ? pow(10, -$precision) : 1;
        $minWick = max($priceTick, $close * 0.00005);
        $maxWick = max($minWick, $close * 0.0003);
        $seedSource = $timestamp . '|' . number_format($open, $precision, '.', '') . '|' . number_format($close, $precision, '.', '');
        $seed = (int) sprintf('%u', crc32($seedSource));

        $upperRatio = 0.24 + (($seed % 63) / 100);
        $lowerRatio = 0.24 + ((intdiv($seed, 97) % 63) / 100);

        if (abs($upperRatio - $lowerRatio) < 0.12) {
            if (($seed % 2) === 0) {
                $upperRatio = min(0.9, $upperRatio + 0.18);
                $lowerRatio = max(0.22, $lowerRatio - 0.07);
            } else {
                $lowerRatio = min(0.9, $lowerRatio + 0.18);
                $upperRatio = max(0.22, $upperRatio - 0.07);
            }
        }

        $upperWick = min($maxWick, max($minWick, $maxWick * $upperRatio));
        $lowerWick = min($maxWick, max($minWick, $maxWick * $lowerRatio));

        $high = $bodyHigh + $upperWick;
        $low = $bodyLow - $lowerWick;

        return [
            round(max($high, $bodyHigh), $precision),
            round(min($low, $bodyLow), $precision),
        ];
    }

    protected function findCandleCloseAtTimestamp(array $candles, int $targetTimestamp, $resolution): float
    {
        if ($targetTimestamp <= 0 || empty($candles['t']) || empty($candles['c'])) {
            return 0;
        }

        $resolution = $this->normalizeResolutionKey($resolution);
        $count = count($candles['t']);
        $lastClose = 0;

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);
            $close = (float) ($candles['c'][$i] ?? 0);

            if ($close > 0) {
                $lastClose = $close;
            }

            if ($timestamp <= $targetTimestamp && $this->getCandleEndTimestamp($timestamp, $resolution) > $targetTimestamp) {
                return $close > 0 ? $close : $lastClose;
            }
        }

        return 0;
    }

    protected function getManualKlineOverrideTimestamps($market, $resolution, int $precision): array
    {
        $stored = $this->buildStoredKlineOverrideMapForResolution(
            $market,
            $this->normalizeResolutionKey($resolution),
            $precision
        );

        if (empty($stored)) {
            return [];
        }

        $result = [];

        foreach ($stored as $timestamp => $item) {
            if (!empty($item['manual_current']) || !empty($item['_has_manual_current'])) {
                $result[(string) $timestamp] = true;
            }
        }

        return $result;
    }

    protected function getCurrentKlineAdjustmentTimestamp(int $marketId): int
    {
        if ($marketId <= 0) {
            return 0;
        }

        $adjustedAt = Cache::get($this->getMarketKlineAdjustedCacheKey($marketId));

        if (!$adjustedAt) {
            return 0;
        }

        $timestamp = strtotime((string) $adjustedAt);

        if ($timestamp <= 0 || $this->isKlineAdjustmentClearedAfter($marketId, $timestamp)) {
            return 0;
        }

        return $timestamp;
    }

    protected function isKlineAdjustmentClearedAfter(int $marketId, int $adjustedTimestamp): bool
    {
        if ($marketId <= 0 || $adjustedTimestamp <= 0) {
            return false;
        }

        $deletedAt = $this->getCachedKlineDeletedAt($marketId);

        if (!$deletedAt) {
            return false;
        }

        $deletedTimestamp = strtotime((string) $deletedAt);

        return $deletedTimestamp > 0 && $deletedTimestamp >= $adjustedTimestamp;
    }

    protected function isKlineLockCleared(int $marketId, int $candleTimestamp): bool
    {
        if ($marketId <= 0 || $candleTimestamp <= 0) {
            return false;
        }

        $deletedAt = $this->getCachedKlineDeletedAt($marketId);

        if (!$deletedAt) {
            return false;
        }

        $deletedTimestamp = strtotime((string) $deletedAt);

        return $deletedTimestamp > 0 && $candleTimestamp <= $deletedTimestamp;
    }

    protected function getCurrentKlineAdjustmentPriceForCandle(int $marketId): float
    {
        if ($marketId <= 0) {
            return 0;
        }

        if(array_key_exists($marketId, $this->klineAdjustedPriceCache)) {
            return $this->klineAdjustedPriceCache[$marketId];
        }

        $adjustedPrice = Cache::get('market_kline_adjusted_price_' . $marketId, []);
        $price = is_array($adjustedPrice) ? (float) ($adjustedPrice['price'] ?? 0) : 0;

        if ($price > 0) {
            return $this->klineAdjustedPriceCache[$marketId] = $price;
        }

        $runtimeConfig = $this->getKlineRuntimeConfig($marketId);

        if (isset($runtimeConfig['last']) && (float) $runtimeConfig['last'] > 0) {
            return $this->klineAdjustedPriceCache[$marketId] = (float) $runtimeConfig['last'];
        }

        return $this->klineAdjustedPriceCache[$marketId] = (float) market_get_stats($marketId, 'last');
    }

    protected function getCachedKlineAdjustedAt(int $marketId)
    {
        if(!array_key_exists($marketId, $this->klineAdjustedAtCache)) {
            $this->klineAdjustedAtCache[$marketId] = Cache::get($this->getMarketKlineAdjustedCacheKey($marketId));
        }

        return $this->klineAdjustedAtCache[$marketId];
    }

    protected function getCachedKlineDeletedAt(int $marketId)
    {
        if(!array_key_exists($marketId, $this->klineDeletedAtCache)) {
            $this->klineDeletedAtCache[$marketId] = Cache::get('market_kline_deleted_at_' . $marketId);
        }

        return $this->klineDeletedAtCache[$marketId];
    }

    protected function normalizeKlinePriceUnitWithRuntimeLast(array $candles, $market, int $precision, $resolution): array
    {
        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $marketId = (int) ($market->id ?? 0);

        if ($marketId <= 0) {
            return $candles;
        }

        // A legitimate BS change can differ greatly from the previous ticker.
        // Do not undo it using a stale runtime price and then lock that old price.
        // Explicit K-line adjustments retain their existing unit repair path.
        if ($this->getCurrentKlineAdjustmentTimestamp($marketId) <= 0) {
            return $candles;
        }

        $targetPrice = $this->getCurrentKlineAdjustmentPriceForCandle($marketId);

        if ($targetPrice <= 0) {
            return $candles;
        }

        $referenceClose = $this->findRuntimeUnitReferenceClose($candles, $resolution);

        if ($referenceClose <= 0 || !$this->hasSeverePriceUnitMismatch($referenceClose, $targetPrice)) {
            return $candles;
        }

        $ratio = $targetPrice / $referenceClose;

        if ($ratio <= 0) {
            return $candles;
        }

        return $this->scaleKlinePriceFields($candles, $ratio, $precision);
    }

    protected function findRuntimeUnitReferenceClose(array $candles, $resolution): float
    {
        if (empty($candles['t']) || empty($candles['c'])) {
            return 0;
        }

        $resolution = $this->normalizeResolutionKey($resolution);
        $count = count($candles['t']);
        $latestClose = 0;
        $latestTimestamp = 0;

        for ($i = $count - 1; $i >= 0; $i--) {
            if (!isset($candles['t'][$i], $candles['c'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);
            $close = (float) $candles['c'][$i];

            if ($timestamp <= 0 || $close <= 0) {
                continue;
            }

            if ($this->isCurrentOpenCandle($timestamp, $resolution)) {
                return $close;
            }

            if ($latestTimestamp <= 0) {
                $latestTimestamp = $timestamp;
                $latestClose = $close;
            }
        }

        if ($latestTimestamp <= 0 || $latestClose <= 0) {
            return 0;
        }

        $maxLag = max($this->resolutionToSeconds($resolution) * 3, 180);

        if ($this->getCandleEndTimestamp($latestTimestamp, $resolution) < (time() - $maxLag)) {
            return 0;
        }

        return $latestClose;
    }

    protected function hasSeverePriceUnitMismatch(float $sourcePrice, float $targetPrice): bool
    {
        if ($sourcePrice <= 0 || $targetPrice <= 0) {
            return false;
        }

        $ratio = $targetPrice / $sourcePrice;

        return $ratio >= 1.5 || $ratio <= 0.6666667;
    }

    protected function scaleKlinePriceFields(array $candles, float $ratio, int $precision): array
    {
        $fields = ['o', 'h', 'l', 'c'];
        $count = count($candles['t'] ?? []);

        for ($i = 0; $i < $count; $i++) {
            foreach ($fields as $field) {
                if (!isset($candles[$field][$i])) {
                    continue;
                }

                $value = (float) $candles[$field][$i];

                if ($value <= 0) {
                    continue;
                }

                $candles[$field][$i] = round($value * $ratio, $precision);
            }

            if (!isset($candles['o'][$i], $candles['h'][$i], $candles['l'][$i], $candles['c'][$i])) {
                continue;
            }

            $open = (float) $candles['o'][$i];
            $close = (float) $candles['c'][$i];
            $high = (float) $candles['h'][$i];
            $low = (float) $candles['l'][$i];

            if ($open > 0 && $close > 0) {
                $candles['h'][$i] = round(max($high, $open, $close), $precision);
                $candles['l'][$i] = round(min($low, $open, $close), $precision);
            }
        }

        return $candles;
    }

    protected function multiplyKlinePricesForSpecialMarket(array $candles, $market, int $precision): array
    {
        /*
         * K线价格倍数读取 markets.bs。
         *
         * 普通市场：
         * bs > 0 时，open / high / low / close 全部乘以 bs（30% 存为 0.3）。
         *
         * 特殊市场 market_id = 14：
         * 已知外部行情最早从 2026-04-20 开始。
         * 从第 8 天开始，也就是 2026-04-28 00:00:00 起，才乘 bs。
         * 2026-04-20 ~ 2026-04-27 保持原始价格。
         *
         * 注意：
         * 这个倍数只在最终返回前处理，不写入本地 K 线缓存，
         * 防止刷新后重复乘倍数。
         */
        $multiplier = MarketPriceMultiplier::resolve($market->bs ?? null);

        if ($multiplier === 1.0) {
            return $candles;
        }

        if (
            empty($candles['t']) ||
            empty($candles['o']) ||
            empty($candles['h']) ||
            empty($candles['l']) ||
            empty($candles['c'])
        ) {
            return $candles;
        }

        $marketId = (int) ($market->id ?? 0);
        $bsStartTimestamp = $this->getBsMultiplierStartTimestamp($market);

        $fields = ['o', 'h', 'l', 'c'];
        $count = count($candles['t']);

        for ($i = 0; $i < $count; $i++) {
            if (!isset($candles['t'][$i])) {
                continue;
            }

            $timestamp = $this->normalizeCandleTimestamp($candles['t'][$i]);

            /*
             * market_id = 14：
             * 只有 2026-04-28 00:00:00 之后的 K 线才乘 bs。
             */
            if ($marketId === 14 && $bsStartTimestamp > 0 && $timestamp < $bsStartTimestamp) {
                continue;
            }

            foreach ($fields as $field) {
                if (!isset($candles[$field][$i])) {
                    continue;
                }

                $candles[$field][$i] = round((float) $candles[$field][$i] * $multiplier, $precision);
            }
        }

        return $candles;
    }

    protected function getBsMultiplierStartTimestamp($market): int
    {
        /*
         * market_id = 14：
         * 外部行情最早是 2026-04-20。
         * 第 8 天开始乘倍数，即 2026-04-28 00:00:00 UTC。
         */
        if ((int) ($market->id ?? 0) === 14) {
            return Carbon::create(2026, 4, 20, 0, 0, 0, 'UTC')
                ->addDays(8)
                ->timestamp;
        }

        /*
         * 其他市场不限制开始时间。
         */
        return 0;
    }

    protected function normalizeCandleTimestamp($timestamp): int
    {
        $timestamp = (int) $timestamp;

        if ($timestamp > 20000000000) {
            return (int) floor($timestamp / 1000);
        }

        return $timestamp;
    }

    protected function normalizeResolutionKey($resolution): string
    {
        $raw = trim((string) $resolution);

        if ($raw === '') {
            return '1';
        }

        if (is_numeric($raw)) {
            return (string) max(1, (int) $raw);
        }

        if (preg_match('/^(\d+)m$/', $raw, $matches)) {
            return (string) max(1, (int) $matches[1]);
        }

        if (preg_match('/^(\d+)\s*(MIN|MINS|MINUTE|MINUTES)$/i', $raw, $matches)) {
            return (string) max(1, (int) $matches[1]);
        }

        if (preg_match('/^(\d+)\s*(HOUR|HOURS)$/i', $raw, $matches)) {
            return (string) (max(1, (int) $matches[1]) * 60);
        }

        if (preg_match('/^(\d+)\s*(DAY|DAYS)$/i', $raw, $matches)) {
            return max(1, (int) $matches[1]) . 'D';
        }

        if (preg_match('/^(\d+)\s*(WEEK|WEEKS)$/i', $raw, $matches)) {
            return max(1, (int) $matches[1]) . 'W';
        }

        if (preg_match('/^(\d+)\s*(MONTH|MONTHS)$/i', $raw, $matches)) {
            return max(1, (int) $matches[1]) . 'M';
        }

        $upper = strtoupper($raw);

        if ($upper === 'D') {
            return '1D';
        }

        if ($upper === 'W') {
            return '1W';
        }

        if ($upper === 'M') {
            return '1M';
        }

        if (preg_match('/^(\d+)H$/', $upper, $matches)) {
            return (string) (max(1, (int) $matches[1]) * 60);
        }

        if (preg_match('/^(\d+)D$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) . 'D';
        }

        if (preg_match('/^(\d+)W$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) . 'W';
        }

        if (preg_match('/^(\d+)M$/', $upper, $matches)) {
            return max(1, (int) $matches[1]) . 'M';
        }

        return $upper;
    }

    protected function resolutionToSeconds($resolution): int
    {
        $resolutionKey = $this->normalizeResolutionKey($resolution);

        if (is_numeric($resolutionKey)) {
            return max(1, (int) $resolutionKey) * 60;
        }

        if (preg_match('/^(\d+)D$/', $resolutionKey, $matches)) {
            return max(1, (int) $matches[1]) * 86400;
        }

        if (preg_match('/^(\d+)W$/', $resolutionKey, $matches)) {
            return max(1, (int) $matches[1]) * 604800;
        }

        if (preg_match('/^(\d+)M$/', $resolutionKey, $matches)) {
            return max(1, (int) $matches[1]) * 2592000;
        }

        return 60;
    }

    protected function getResolutionBucketStartTimestamp(int $timestamp, $resolution): int
    {
        $resolutionKey = $this->normalizeResolutionKey($resolution);

        if (is_numeric($resolutionKey)) {
            $seconds = max(1, (int) $resolutionKey) * 60;

            return (int) floor($timestamp / $seconds) * $seconds;
        }

        if (preg_match('/^(\d+)D$/', $resolutionKey, $matches)) {
            $days = max(1, (int) $matches[1]);
            $carbon = Carbon::createFromTimestamp($timestamp)->startOfDay();

            if ($days === 1) {
                return $carbon->timestamp;
            }

            $dayStart = $carbon->timestamp;
            $seconds = $days * 86400;

            return (int) floor($dayStart / $seconds) * $seconds;
        }

        if (preg_match('/^(\d+)W$/', $resolutionKey, $matches)) {
            $weeks = max(1, (int) $matches[1]);

            $carbon = Carbon::createFromTimestamp($timestamp)
                ->startOfWeek(Carbon::MONDAY);

            if ($weeks === 1) {
                return $carbon->timestamp;
            }

            $seconds = $weeks * 604800;

            return (int) floor($carbon->timestamp / $seconds) * $seconds;
        }

        if (preg_match('/^(\d+)M$/', $resolutionKey, $matches)) {
            return Carbon::createFromTimestamp($timestamp)
                ->startOfMonth()
                ->timestamp;
        }

        return $timestamp;
    }

    protected function getCandleEndTimestamp(int $timestamp, $resolution): int
    {
        $resolutionKey = $this->normalizeResolutionKey($resolution);
        $bucketStart = $this->getResolutionBucketStartTimestamp($timestamp, $resolutionKey);

        if (is_numeric($resolutionKey)) {
            return $bucketStart + (max(1, (int) $resolutionKey) * 60);
        }

        if (preg_match('/^(\d+)D$/', $resolutionKey, $matches)) {
            return Carbon::createFromTimestamp($bucketStart)
                ->addDays(max(1, (int) $matches[1]))
                ->timestamp;
        }

        if (preg_match('/^(\d+)W$/', $resolutionKey, $matches)) {
            return Carbon::createFromTimestamp($bucketStart)
                ->addWeeks(max(1, (int) $matches[1]))
                ->timestamp;
        }

        if (preg_match('/^(\d+)M$/', $resolutionKey, $matches)) {
            return Carbon::createFromTimestamp($bucketStart)
                ->addMonths(max(1, (int) $matches[1]))
                ->timestamp;
        }

        return $bucketStart + 60;
    }

    protected function getInternalHistory(Request $request, $market)
    {
        $marketService = new MarketService();
        $transactions = $marketService->getCandles($market);

        if ($transactions && $transactions->count() > 0) {
            $transactions->each(function ($transaction) {
                $this->candles['t2'][] = str_replace('.000000', '', $transaction->date);
                $this->candles['t'][] = $transaction->date;
                $this->candles['o'][] = $transaction->open;
                $this->candles['c'][] = $transaction->close;
                $this->candles['h'][] = $transaction->high;
                $this->candles['l'][] = $transaction->low;
            });

            $this->candles['s'] = 'ok';
        } else {
            $this->candles['s'] = 'no_data';
        }

        $precision = (int) $market->quote_precision;

        /**
         * 内部 K 线也走同一套 bs 倍率规则。
         */
        $this->candles = $this->multiplyKlinePricesForSpecialMarket($this->candles, $market, $precision);
        $resolution = $this->normalizeResolutionKey($request->get('resolution', '5'));
        $this->candles = $this->normalizeKlinePriceUnitWithRuntimeLast($this->candles, $market, $precision, $resolution);
        $this->candles = $this->syncCurrentOpenCandleWithRuntimeLast($this->candles, $market, $precision, $resolution);
        $this->candles = $this->clampRecentKlineAdjustmentCandles($this->candles, $market, $precision, $resolution);
        $this->candles = $this->fixCandlesOpenByPreviousClose($this->candles, $precision, $resolution, $market);

        return response()->json($this->candles);
    }

    protected function normalizeSymbol(string $symbol): string
    {
        return str_replace(['-', '/', '_'], '', strtoupper($symbol));
    }

    public function search(Request $request)
    {
        $query = trim((string) $request->get('query', ''));
        $symbol = trim((string) $request->get('symbol', ''));

        $market = null;

        /**
         * TradingView 搜索框也只返回平台自己的交易对名称。
         * 不再把外部行情源的代币名暴露出来。
         */
        if ($query !== '') {
            $market = $this->resolveMarketForChartSymbol($query);
        }

        if (!$market && $symbol !== '') {
            $market = $this->resolveMarketForChartSymbol($symbol);
        }

        if (!$market) {
            return response()->json([]);
        }

        $platformSymbol = (string) $market->name;
        $platformExchange = config('app.name');

        return response()->json([
            [
                'symbol' => $platformSymbol,
                'ticker' => $platformSymbol,
                'name' => $platformSymbol,
                'full_name' => $platformSymbol,
                'description' => $platformSymbol,
                'exchange' => $platformExchange,
                'listed_exchange' => $platformExchange,
                'type' => MARKET_CHART_TYPE,
            ]
        ]);
    }

    public function config()
    {
        return response()->json(MARKET_CHART_CONFIGS);
    }

    public function time()
    {
        return response()->json(Carbon::now()->timestamp);
    }

    public function candles(Request $request)
    {
        return $this->history($request);
    }
}
