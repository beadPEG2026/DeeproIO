<?php

namespace App\Http\Controllers\Api\v1;

use App\Services\Market\MarketPriceMultiplier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Market\MarketDataRequest;
use App\Http\Resources\Market\Market;
use App\Models\Market\Market as MarketModel;
use App\Http\Resources\Market\Market as MarketResource;
use App\Http\Resources\Market\MarketCollection;
use App\Http\Resources\Transaction\Candles\CandleCollection;
use App\Http\Resources\Transaction\TransactionCollection;
use App\Models\Order\Order;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Order\OrderRepository;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\Market\MarketService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * @tags Market Data
 */
class MarketController extends Controller
{
    /**
     * @var marketService
     */
    protected $marketService;

    /**
     * PostController Constructor
     *
     * @param MarketService $marketService
     *
     */
    public function __construct(MarketService $marketService)
    {
        $this->marketService = $marketService;
    }

    /**
     * Get Market Ticker
     *
     * Retrieves 24-hour price statistics for one or all trading pairs.
     * If market parameter is provided, returns data for that specific pair.
     * Otherwise, returns data for all active markets.
     *
     * **Ticker Data Includes:** Last price, bid/ask prices, 24h high/low/volume, price change
     *
     * @operationId getMarketTicker
     *
     * @return Market|MarketCollection
     */
    #[QueryParameter('market', description: 'Specific market pair name. If omitted, returns all markets', type: 'string', example: 'BTC-USDT')]
    public function ticker(Request $request)
    {
        $market = $request->get('market', null);

        if($market) {
            return new Market($this->marketService->getMarket($market));
        }

        return new MarketCollection($this->marketService->getMarkets(false));
    }

    /**
     * Get Order Book
     *
     * Retrieves the current order book (bids and asks) for a specific market.
     * Orders are aggregated by price level and sorted by price.
     *
     * @operationId getOrderBook
     *
     * @return array
     */
    #[QueryParameter('market', description: 'The market pair name', required: true, type: 'string', example: 'BTC-USDT')]
    public function orderbook(MarketDataRequest $request) {

        $market = $request->get('market');

        $model = MarketModel::whereName($market)->first();

        $liquidity=app(\App\Services\Market\FundedLiquidity::class);
        $book=$liquidity->publicBook($model);
        return $book + ['book_status'=>\App\Services\Market\OrderBookStatus::metadata($model,$book),
            'execution_mode'=>$liquidity->policy($model)->mode ?? 'internal',
            'market_status'=>\App\Services\Market\HongKongPriceProduct::isMarket($model)
                ? (app(\App\Services\Market\HongKongPriceProduct::class)->sessionOpen()?'open':'closed') : 'open',
        ];
    }

    protected function rebaseOrderbookAfterKlineAdjustment(MarketModel $model, string $market, $bids, $asks): array
    {
        $rebaseConfig = Cache::get('market_orderbook_rebase_' . $market, []);
        $targetPrice = (float) ($rebaseConfig['last'] ?? 0);
        $runtimeTargetPrice = $this->getRuntimeLastPriceForRebase($model);

        if ($runtimeTargetPrice > 0 && (!empty($rebaseConfig['adjusted_at']) || $this->hasRecentKlineAdjustment((int)$model->id))) {
            $targetPrice = $runtimeTargetPrice;
        }

        if ($targetPrice <= 0) {
            $runtimeConfig = Cache::get('market_kline_runtime_config_' . (int)$model->id, []);

            if (is_array($runtimeConfig) && isset($runtimeConfig['last'])) {
                $targetPrice = (float)$runtimeConfig['last'];
            }

            if ($targetPrice <= 0 && $this->hasRecentKlineAdjustment((int)$model->id)) {
                $targetPrice = (float)market_get_stats((int)$model->id, 'last');
            }

            if ($targetPrice <= 0) {
                return [$bids, $asks];
            }
        }

        [$bidsCollection, $asksCollection] = $this->normalizeOrderbookUnitAgainstTarget(
            $model,
            $bids,
            $asks,
            $targetPrice
        );

        $bestBid = $bidsCollection->pluck('price')->map(fn ($price) => (float) $price)->filter(fn ($price) => $price > 0)->max();
        $bestAsk = $asksCollection->pluck('price')->map(fn ($price) => (float) $price)->filter(fn ($price) => $price > 0)->min();
        $middle = 0;

        if ($bestBid > 0 && $bestAsk > 0) {
            $middle = ($bestBid + $bestAsk) / 2;
        } elseif ($bestBid > 0) {
            $middle = $bestBid;
        } elseif ($bestAsk > 0) {
            $middle = $bestAsk;
        }

        if ($middle <= 0) {
            return [$bidsCollection, $asksCollection];
        }

        $ratio = $targetPrice / $middle;

        if ($ratio <= 0 || abs($ratio - 1) < 0.0000001) {
            return [$bidsCollection, $asksCollection];
        }

        $precision = (int) ($model->quote_precision ?? 8);

        return [
            $this->rebaseOrderbookCollection($bidsCollection, $ratio, $precision)->sortByDesc('price')->values(),
            $this->rebaseOrderbookCollection($asksCollection, $ratio, $precision)->sortBy('price')->values(),
        ];
    }

    protected function getRuntimeLastPriceForRebase(MarketModel $model): float
    {
        $marketId = (int)$model->id;

        if ($marketId <= 0) {
            return 0;
        }

        $runtimeConfig = Cache::get('market_kline_runtime_config_' . $marketId, []);

        if (is_array($runtimeConfig) && isset($runtimeConfig['last']) && (float)$runtimeConfig['last'] > 0) {
            return (float)$runtimeConfig['last'];
        }

        return (float)market_get_stats($marketId, 'last');
    }

    protected function rebaseOrderbookCollection($orders, float $ratio, int $precision)
    {
        return collect($orders)->map(function ($order) use ($ratio, $precision) {
            $order = is_array($order) ? $order : (array) $order;

            if (!isset($order['price'])) {
                return $order;
            }

            $order['price'] = math_formatter((float) $order['price'] * $ratio, $precision);

            return $order;
        });
    }

    protected function normalizeOrderbookUnitAgainstTarget(MarketModel $model, $bids, $asks, float $targetPrice): array
    {
        $precision = (int) ($model->quote_precision ?? 8);
        $multiplier = $this->getTradePriceMultiplier($model);

        return [
            $this->normalizeOrderbookSideAgainstTarget($bids, $targetPrice, $multiplier, $precision, 'bid')
                ->sortByDesc('price')
                ->values(),
            $this->normalizeOrderbookSideAgainstTarget($asks, $targetPrice, $multiplier, $precision, 'ask')
                ->sortBy('price')
                ->values(),
        ];
    }

    protected function normalizeOrderbookSideAgainstTarget($orders, float $targetPrice, float $multiplier, int $precision, string $side)
    {
        return collect($orders)
            ->map(function ($order) use ($targetPrice, $multiplier, $precision, $side) {
                $order = is_array($order) ? $order : (array) $order;

                if (!isset($order['price']) || $targetPrice <= 0) {
                    return $order;
                }

                $price = (float) $order['price'];

                if ($price <= 0) {
                    return null;
                }

                $price = $this->normalizeOrderbookPriceAgainstTarget($price, $targetPrice, $multiplier);
                $diffRatio = abs($price - $targetPrice) / $targetPrice;

                /*
                 * 重启任务后可能会同时混入未乘 bs 的外部盘口和历史缓存里的异常档位。
                 * 买价明显高于现价、卖价明显低于现价时会把中间价“平均”成正常值，必须剔除。
                 */
                if (
                    $diffRatio > 0.25 &&
                    (($side === 'bid' && $price > $targetPrice) || ($side === 'ask' && $price < $targetPrice))
                ) {
                    return null;
                }

                $order['price'] = math_formatter($price, $precision);

                return $order;
            })
            ->filter()
            ->values();
    }

    protected function normalizeOrderbookPriceAgainstTarget(float $price, float $targetPrice, float $multiplier): float
    {
        if ($price <= 0 || $targetPrice <= 0 || $multiplier <= 0 || $multiplier === 1.0) {
            return $price;
        }

        $candidates = [$price, $price * $multiplier, $price / $multiplier];
        $bestPrice = $price;
        $bestDiff = abs($price - $targetPrice) / $targetPrice;

        foreach ($candidates as $candidate) {
            if ($candidate <= 0) {
                continue;
            }

            $diff = abs($candidate - $targetPrice) / $targetPrice;

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestPrice = $candidate;
            }
        }

        return $bestPrice;
    }

    /**
     * Get Recent Trades
     *
     * Retrieves the most recent trades for a specific market.
     * Returns trades in reverse chronological order (newest first).
     *
     * @operationId getRecentTrades
     *
     * @return TransactionCollection
     */
    #[QueryParameter('market', description: 'The market pair name', required: true, type: 'string', example: 'BTC-USDT')]
public function trades(MarketDataRequest $request)
{
    $marketName = $request->get('market');

    $market = MarketModel::whereName($marketName)->first();

    if (!$market) {
        return response()->json([
            'data' => [],
            'trades' => [],
            'success' => false,
        ]);
    }

    if (\App\Services\Market\StockAssets::supports($market->name)) return new TransactionCollection($this->marketService->getTrades($market->name,20,false,true));

    if ($this->isKlineAdjustmentTradeSuppressed((int)$market->id)) {
        return response()->json([
            'data' => [],
            'trades' => [],
            'success' => true,
        ]);
    }

    $binance = new BinanceApi();

    $externalSymbol = $this->getExternalTradeSymbol($market);
    $priceMultiplier = $this->getTradePriceMultiplier($market);

    try {
        /*
         * 直接从交易所获取最近 20 条历史成交。
         * 外部 symbol 使用 chart_symbol，前端显示仍然用本地 market name。
         */
        $trades = $binance->historicalTrades($externalSymbol, '20');

        $sanitized = [];
        $referencePrice = 0;

        foreach ($trades as $trade) {
            $rawPrice = (float)($trade['price'] ?? 0);

            if ($rawPrice > 0) {
                $referencePrice = $rawPrice * $priceMultiplier;
                break;
            }
        }

        foreach ($trades as $trade) {
            $rawPrice = (float)($trade['price'] ?? 0);
            $quantity = (float)($trade['qty'] ?? 0);
            $displayPrice = $this->rebaseTradePriceToRuntimeLast(
                $market,
                $rawPrice * $priceMultiplier,
                $referencePrice
            );

            $sanitized[] = [
                'id' => $trade['id'] ?? null,
                'market' => $market->name,
                'created_at' => isset($trade['time'])
                    ? Carbon::createFromTimestampMs($trade['time'])->toIso8601String()
                    : now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'price' => math_formatter($displayPrice, $market->quote_precision),
                'quantity' => math_formatter($quantity, $market->base_precision),
                'side' => !empty($trade['isBuyerMaker']) ? 'buy' : 'sell',
            ];
        }

        /*
         * 同时返回 data 和 trades。
         * data 兼容原来的 ResourceCollection 风格；
         * trades 兼容你 historicalTrades 之前的返回风格。
         */
        return response()->json([
            'data' => $sanitized,
            'trades' => $sanitized,
            'success' => true,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'data' => [],
            'trades' => [],
            'success' => false,
            'message' => 'trades_fetch_failed',
        ]);
    }
}
protected function getExternalTradeSymbol($market): string
{
    $chartSymbol = trim((string)($market->chart_symbol ?? ''));

    if ($chartSymbol !== '') {
        return strtoupper(str_replace(['-', '/', '_', ' '], '', $chartSymbol));
    }

    return market_sanitize($market->name);
}

protected function isKlineAdjustmentTradeSuppressed(int $marketId): bool
{
    if ($marketId <= 0) {
        return false;
    }

    if (!$this->isKlineAdjustmentRuntimeActive($marketId)) {
        return false;
    }

    $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

    if (!$adjustedAt) {
        return false;
    }

    $timestamp = strtotime((string)$adjustedAt);

    return $timestamp > 0 && (time() - $timestamp) <= 30;
}

protected function isKlineAdjustmentRuntimeActive(int $marketId): bool
{
    $runtimeConfig = Cache::get('market_kline_runtime_config_' . $marketId, []);

    if (!is_array($runtimeConfig)) {
        $runtimeConfig = [];
    }

    if (array_key_exists('custom_liquidity_t', $runtimeConfig)) {
        $customActive = filter_var($runtimeConfig['custom_liquidity_t'], FILTER_VALIDATE_BOOLEAN);
    } else {
        $customActive = (bool) optional(MarketModel::find($marketId))->custom_liquidity_t;
    }

    if (!$customActive) {
        return false;
    }

    $percent = array_key_exists('bot_price_ceiling', $runtimeConfig)
        ? (float) $runtimeConfig['bot_price_ceiling']
        : (float) optional(MarketModel::find($marketId))->bot_price_ceiling;

    return abs($percent) > 0.00000001;
}

protected function hasRecentKlineAdjustment(int $marketId): bool
{
    if ($marketId <= 0) {
        return false;
    }

    $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

    if (!$adjustedAt) {
        return false;
    }

    $timestamp = strtotime((string)$adjustedAt);

    return $timestamp > 0 && (time() - $timestamp) <= 86400;
}

protected function rebaseTradePriceToRuntimeLast($market, float $price, float $referencePrice): float
{
    $marketId = (int)($market->id ?? 0);

    if ($price <= 0) {
        return $price;
    }

    if (!$this->hasRecentKlineAdjustment($marketId)) {
        return $price;
    }

    $runtimeLast = (float)market_get_stats($marketId, 'last');

    if ($runtimeLast <= 0) {
        return $price;
    }

    if ($referencePrice <= 0) {
        return $runtimeLast;
    }

    $ratio = $runtimeLast / $referencePrice;

    if ($ratio <= 0) {
        return $runtimeLast;
    }

    return $price * $ratio;
}

protected function getTradePriceMultiplier($market): float
{
    return MarketPriceMultiplier::resolve($market->bs);
}

    /**
     * Get OHLCV Candles
     *
     * Retrieves candlestick (OHLCV) data for charting.
     * Supported intervals: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
     *
     * @operationId getOHLCVCandles
     *
     * @return CandleCollection
     */
    #[QueryParameter('market', description: 'The market pair name', required: true, type: 'string', example: 'BTC-USDT')]
    #[QueryParameter('interval', description: 'Candlestick interval (1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w)', type: 'string', example: '1h')]
    #[QueryParameter('limit', description: 'Number of candles to return', type: 'integer', example: 100)]
    public function candles(MarketDataRequest $request)
    {
        return new CandleCollection($this->marketService->getCandles());
    }

    /**
     * Get Market Info
     *
     * Retrieves detailed information about a specific market pair.
     * Includes trading rules, precision settings, and fee information.
     *
     * @operationId getMarketInfo
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('market', description: 'The market pair name', required: true, type: 'string', example: 'BTC-USDT')]
    public function marketInfo(Request $request)
    {
        $model = (new MarketRepository())->get($request->get('market'));

        if(!$model) {
            return response()->json(['result' => false, 'message' => __('Wrong market name')]);
        }

        $market = new MarketResource($model);


        return response()->json($market);
    }

    #[ExcludeRouteFromDocs]

    public function index()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]

    public function create()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]

    public function store()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]

    public function show()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]

    public function edit()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    /**
     * Get Historical Trades
     *
     * Retrieves historical trades from liquidity provider for markets with liquidity bridging.
     * Returns the most recent trades from external liquidity sources.
     *
     * @operationId getHistoricalTrades
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('market', description: 'The market pair name', required: true, type: 'string', example: 'BTC-USDT')]
public function historicalTrades(Request $request)
{

    $market = MarketModel::whereName($request->get('market'))->first();

    if (!$market) return response()->json(['trades'=>[], 'success'=>false], 404);

    if (\App\Services\Market\StockAssets::supports($market->name)) {
        $localTrades = $this->marketService->getTrades($market->name, 30, false, true)->map(fn($trade) => [
            'id' => 'deepro:'.$trade->id, 'source' => 'deepro', 'side' => $trade->order_side,
            'price' => (string)$trade->price, 'quantity' => (string)$trade->base_currency,
            'timestamp' => $trade->created_at->getTimestampMs(), 'created_at' => $trade->created_at->toIso8601String(),
        ])->all();
        if (\App\Services\Market\HongKongPriceProduct::isMarket($market)) {
            return response()->json(['trades'=>$localTrades,'success'=>true,'source'=>'deepro','stale'=>false]);
        }
        try {
            $feed = app(\App\Services\Market\StockDataClient::class)->call('/v1/stocks/trades', [
                'symbol' => $market->baseCurrency->symbol, 'limit' => 30,
            ]);
            $source = $feed['source'] ?? 'binance-alpha';
            $asset = \App\Services\Market\StockAssets::find($market->baseCurrency->symbol);
            if ($source !== ($asset['marketSource'] ?? 'binance-alpha')) throw new \RuntimeException('stock_trade_source_mismatch');
            $external = array_map(fn($trade) => array_merge($trade, [
                'id' => $source.':'.$trade['id'], 'source' => $source,
            ]), $feed['data']);
            $trades = collect(array_merge($external, $localTrades))->unique('id')
                ->sortByDesc('timestamp')->take(30)->values()->all();
            return response()->json([
                'trades' => $trades, 'success' => true, 'source' => 'market-data',
                'source_symbol' => $feed['source_symbol'], 'received_at' => $feed['received_at'],
                'stale' => (bool)($feed['stale'] ?? false),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['trades' => $localTrades, 'success' => true, 'source' => 'market-data',
                'stale' => true, 'message' => __('Market data is temporarily unavailable. Please try again.')]);
        }
    }

    $chartSymbol = trim((string)($market->chart_symbol ?? ''));

    if ($chartSymbol !== '') {
        $marketName = strtoupper(str_replace(['-', '/', '_', ' '], '', $chartSymbol));
    } else {
        $marketName = market_sanitize($market->name);
    }

    /*
     * 原来这里只判断 liq。
     * 现在改成：
     * 1. liq = true，可以获取外部成交；
     * 2. chart_symbol 不为空，也可以获取外部成交。
     *
     * 否则你的 OKR-USDT 现在 liq=false，会直接返回空。
     */
    if (!$market->liq && $chartSymbol === '') {
        return response()->json([
            'trades' => [],
            'success' => false,
        ]);
    }

    if ($this->isKlineAdjustmentTradeSuppressed((int)$market->id)) {
        return response()->json([
            'trades' => [],
            'success' => true,
        ]);
    }

    $priceMultiplier = $this->getTradePriceMultiplier($market);

    try {
        $trades = \Illuminate\Support\Facades\Cache::remember('public-market-trades:'.$marketName, 3, function () use ($marketName) {
            $response=\Illuminate\Support\Facades\Http::connectTimeout(3)->timeout(8)
                ->get(rtrim(config('liquidity.market_data_base'), '/').'/api/v3/trades', ['symbol'=>$marketName,'limit'=>10]);
            $response->throw(); $data=$response->json();
            if(!is_array($data)||!array_is_list($data)) throw new \RuntimeException('Invalid public trades response');
            return array_values(array_filter($data,fn($row)=>is_array($row)&&isset($row['price'],$row['qty'],$row['time'],$row['isBuyerMaker'])));
        });
    } catch (\Throwable $e) {
        return response()->json(['trades'=>[], 'success'=>false, 'message'=>__('Market data is temporarily unavailable. Please try again.')], 503);
    }

    $sanitized = [];
    $referencePrice = 0;

    foreach ($trades as $trade) {
        $price = (float)($trade['price'] ?? 0);

        if ($price > 0) {
            $referencePrice = $price * $priceMultiplier;
            break;
        }
    }
    
    foreach ($trades as $trade) {
        $price = (float)($trade['price'] ?? 0);
        $quantity = (float)($trade['qty'] ?? 0);
        $displayPrice = $this->rebaseTradePriceToRuntimeLast(
            $market,
            $price * $priceMultiplier,
            $referencePrice
        );

        $sanitized[] = [
            "created_at" => Carbon::createFromTimestampMs($trade['time'])->toIso8601String(),
            "price" => math_formatter($displayPrice, $market->quote_precision),
            "quantity" => math_formatter($quantity, $market->base_precision),
            "side" => $trade['isBuyerMaker'] ? 'buy' : 'sell',
        ];
    }

    return response()->json([
        'trades' => $sanitized,
        'success' => true,
    ]);
}

    /**
     * Get Swap Configuration
     *
     * Retrieves configuration data for the instant swap/exchange feature.
     * Returns available currencies and default trading pair for quick swaps.
     *
     * @operationId getSwapConfig
     *
     * @response 200 scenario="Success" {
     *   "currencies": {
     *     "1": {
     *       "name": "BTC",
     *       "id": 1,
     *       "logo": "/storage/currencies/btc.png"
     *     },
     *     "2": {
     *       "name": "USDT",
     *       "id": 2,
     *       "logo": "/storage/currencies/usdt.png"
     *     }
     *   },
     *   "defaultBasePair": 1,
     *   "defaultQuotePair": 2
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function swap()
    {
        $currencyRepository = new CurrencyRepository();

        $defaultMarket = setting('general.swap_market', false);

        if($defaultMarket) {
            $defaultPair = MarketModel::whereName($defaultMarket)->first();
        } else {
            $defaultPair = MarketModel::first();
        }

        $currencyCollection = $currencyRepository->all(false);

        $currencies = [];

        $defaultBasePair = '';
        $defaultQuotePair = '';

        foreach ($currencyCollection as $key=>$currency) {
            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path
            ];
        }

        if($defaultPair) {
            $defaultBasePair = $defaultPair->base_currency_id;
            $defaultQuotePair = $defaultPair->quote_currency_id;
        }

        return response()->json([
            'currencies' => $currencies,
            'defaultBasePair' => $defaultBasePair,
            'defaultQuotePair' => $defaultQuotePair,
        ]);
    }
}
