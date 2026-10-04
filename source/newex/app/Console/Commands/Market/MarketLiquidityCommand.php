<?php

namespace App\Console\Commands\Market;

use App\Services\Market\MarketPriceMultiplier;
use App\Events\MarketTradeLiteUpdated;
use App\Events\MarketTradePressureUpdated;
use App\Events\OrderBookSnapshot;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Repositories\Order\OrderRepository;
use App\Services\Liquidity\Binance\BinanceApi;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MarketLiquidityCommand extends Command
{
    protected $signature = 'market:run-liquidity {market}';

    protected $description = 'Command description';

    protected $firstStart = true;

    protected ?string $lastAppliedKlineTradeAdjustedAt = null;

    protected float $lastOrderbookProcessedAt = 0.0;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle($market = null)
    {
        if (!$market) {
            $market = $this->argument('market');
        }

        $model = Market::whereName($market)->first();

        if (!$model) {
            return;
        }

        /**
         * 外部行情 symbol：
         * 1. 如果 market.chart_symbol 不为空，优先使用 chart_symbol
         * 2. 否则使用本地交易对名称 market_sanitize($market)
         *
         * 注意：
         * 这里只影响去 Binance 获取行情的交易对名。
         * 推送给前端、缓存 key、本地订单仍然使用本地交易对名 $market。
         */
        $marketName = $this->getExternalMarketSymbol($market, $model);

        $binanceApi = new BinanceApi();

        $orderRepository = new OrderRepository();

        $ticketIncrement = 1;

        // A live PM2 process can outlive a stalled socket. Exit unsuccessfully so
        // its existing supervisor reconnects, without resetting quotes or markets.
        $health = new \App\Services\Market\DepthStreamHealth(microtime(true));
        $healthTimer = \React\EventLoop\Loop::addPeriodicTimer(5, function () use ($health, $market) {
            if ($health->expired(microtime(true))) {
                Log::warning('Depth stream stalled; reconnecting', ['market' => $market]);
                exit(1);
            }
        });

        $snapshotTimer = null;
        $umiQuoteRecordedAt = 0;
        try {
            $processDepth = function ($data, $symbol, $bidsCollection, $asksCollection, bool $snapshotRefresh = false) use ($model, $market, $orderRepository, &$ticketIncrement, $health, &$umiQuoteRecordedAt) {
                if (!is_array($bidsCollection) || !is_array($asksCollection)) return;
                if (!$snapshotRefresh) $health->received(microtime(true));
                if ($snapshotRefresh) $ticketIncrement = 2;
                if ($ticketIncrement > 10) {
                    $ticketIncrement = 1;
                }

                $ticketIncrement++;

                if ($ticketIncrement % 3 !== 0 || !$this->shouldProcessOrderbook()) {
                    return;
                }

                if ($ticketIncrement % 3 == 0) {
                    try {
                        $model->refresh();
                    } catch (\Exception $e) {
                        //
                    }

                    /**
                     * bot_price_ceiling 现在表示百分比偏移。
                     * custom_liquidity_t = 1 且 bot_price_ceiling != 0 时永久生效，不再判断开始 / 结束时间。
                     * 例如：
                     *  2    => 行情价格 = 真实价格 * 1.02
                     * -2    => 行情价格 = 真实价格 * 0.98
                     */
                    $customPercent = $this->getCustomLiquidityPercent($model);
                    $customActive = $this->getCustomLiquidityActive($model) && abs($customPercent) > 0.0000000001;
                    $customMultiplier = 1 + ($customPercent / 100);

                    if (\App\Services\Market\StablecoinOrientation::inverse($model)) {
                        $inverse = \App\Services\Market\StablecoinOrientation::depth($bidsCollection, $asksCollection);
                        $bidsCollection = $inverse['bids']; $asksCollection = $inverse['asks'];
                    }
                    $asks = new Collection($asksCollection);
                    $bids = new Collection($bidsCollection);

                    $cj = 0;

                    if ($customActive) {
                        $livePrice = $this->getLiveMiddlePrice($bids, $asks, $model);

                        $customPrice = $this->makePercentOffsetPrice(
                            $model,
                            $livePrice,
                            $customPercent
                        );

                        /**
                         * cj 保持原始价格维度，不乘 bs。
                         * 其他行情统计可以先用 cj 修正原始价格，再统一乘 bs。
                         */
                        $cj = (float)math_formatter($livePrice - $customPrice, $model->quote_precision);

                        Cache::put("market_custom_cj.{$market}", $cj, now()->addMinutes(30));
                        Cache::put("market_custom_price.{$market}", $customPrice, now()->addMinutes(30));
                        Cache::put("market_custom_percent.{$market}", $customPercent, now()->addMinutes(30));
                        Cache::put("market_custom_multiplier.{$market}", $customMultiplier, now()->addMinutes(30));
                    } else {
                        $cj = 0;
                        $customMultiplier = 1;

                        Cache::put("market_custom_cj.{$market}", 0, now()->addMinutes(30));
                        Cache::put("market_custom_multiplier.{$market}", 1, now()->addMinutes(30));
                        Cache::forget("market_custom_price.{$market}");
                        Cache::forget("market_custom_percent.{$market}");
                        $this->resetCustomLiquidityTrendCaches($market);
                    }

                    if ($customActive) {
                        $asks = $asks->map(function ($item, $key) use ($asks, $model, $customMultiplier) {
                            return [
                                'price' => $this->applyPercentOffsetToPrice((float)$asks[$key][0], $customMultiplier, $model),
                                'quantity' => $asks[$key][1],
                            ];
                        });

                        Cache::put("markets_liquidity.$market.asks", $asks);
                        Cache::put("markets_liquidity.$market.asks_total", $asks->sum('quantity'));

                        $bids = $bids->map(function ($item, $key) use ($bids, $model, $customMultiplier) {
                            return [
                                'price' => $this->applyPercentOffsetToPrice((float)$bids[$key][0], $customMultiplier, $model),
                                'quantity' => $bids[$key][1],
                            ];
                        });

                        Cache::put("markets_liquidity.$market.bids_total", $bids->sum('quantity'));
                        Cache::put("markets_liquidity.$market.bids", $bids);

                        $bidsModelCache = Cache::get("bidsModelCache.$market");
                        $bidsModelCacheUpdated = Cache::get("bidsModelCache.$market.updated");

                        if (!$bidsModelCache || $bidsModelCacheUpdated || $this->firstStart) {
                            Cache::put("bidsModelCache.$market", $orderRepository->get($market, Order::SIDE_BUY));
                            Cache::put("bidsModelCache.$market.updated", false);
                        }

                        $bidsModelCache = Cache::get("bidsModelCache.$market");

                        $bids = collect($bidsModelCache->map(function ($item) use ($model) {
                            return [
                                'price' => $this->formatLocalOrderbookPrice((float)$item->price, $model),
                                'quantity' => $item->quantity,
                            ];
                        })->toArray())
                            ->merge(Cache::get("markets_liquidity.$market.bids"))
                            ->sortByDesc('price')
                            ->groupBy(['price'])
                            ->map(function ($item) use ($model) {
                                return [
                                    'price' => math_formatter($item->first()['price'], $model->quote_precision),
                                    'quantity' => $item->sum('quantity'),
                                ];
                            })
                            ->values();

                        $asksModelCache = Cache::get("asksModelCache.$market");
                        $asksModelCacheUpdated = Cache::get("asksModelCache.$market.updated");

                        if (!$asksModelCache || $asksModelCacheUpdated || $this->firstStart) {
                            Cache::put("asksModelCache.$market", $orderRepository->get($market, Order::SIDE_SELL));
                            Cache::put("asksModelCache.$market.updated", false);
                        }

                        $asksModelCache = Cache::get("asksModelCache.$market");

                        $asks = collect($asksModelCache->map(function ($item) use ($model) {
                            return [
                                'price' => $this->formatLocalOrderbookPrice((float)$item->price, $model),
                                'quantity' => $item->quantity,
                            ];
                        })->toArray())
                            ->merge(Cache::get("markets_liquidity.$market.asks"))
                            ->sortBy('price')
                            ->groupBy(['price'])
                            ->map(function ($item) use ($model) {
                                return [
                                    'price' => math_formatter($item->first()['price'], $model->quote_precision),
                                    'quantity' => $item->sum('quantity'),
                                ];
                            })
                            ->values();
                    } else {
                        $ratio = (float)$model->discount * 0.01;

                        $asks = $asks->map(function ($item, $key) use ($asks, $ratio, $model) {
                            $price = (float)$asks[$key][0] + ($ratio * (float)$asks[$key][0]);

                            return [
                                'price' => $this->applySpecialMarketPriceMultiplier($price, $model),
                                'quantity' => $asks[$key][1],
                            ];
                        });

                        Cache::put("markets_liquidity.$market.asks", $asks);
                        Cache::put("markets_liquidity.$market.asks_total", $asks->sum('quantity'));

                        $ratio = (float)$model->discount_bid * 0.01;

                        $bids = $bids->map(function ($item, $key) use ($bids, $ratio, $model) {
                            $price = (float)$bids[$key][0] + ($ratio * (float)$bids[$key][0]);

                            return [
                                'price' => $this->applySpecialMarketPriceMultiplier($price, $model),
                                'quantity' => $bids[$key][1],
                            ];
                        });

                        Cache::put("markets_liquidity.$market.bids_total", $bids->sum('quantity'));
                        Cache::put("markets_liquidity.$market.bids", $bids);

                        $bidsModelCache = Cache::get("bidsModelCache.$market");
                        $bidsModelCacheUpdated = Cache::get("bidsModelCache.$market.updated");

                        if (!$bidsModelCache || $bidsModelCacheUpdated || $this->firstStart) {
                            Cache::put("bidsModelCache.$market", $orderRepository->get($market, Order::SIDE_BUY));
                            Cache::put("bidsModelCache.$market.updated", false);
                        }

                        $bidsModelCache = Cache::get("bidsModelCache.$market");

                        $bids = collect($bidsModelCache->map(function ($item) use ($model) {
                            return [
                                'price' => $this->formatLocalOrderbookPrice((float)$item->price, $model),
                                'quantity' => $item->quantity,
                            ];
                        })->toArray())
                            ->merge(Cache::get("markets_liquidity.$market.bids"))
                            ->sortByDesc('price')
                            ->groupBy(['price'])
                            ->map(function ($item) use ($model) {
                                return [
                                    'price' => math_formatter($item->first()['price'], $model->quote_precision),
                                    'quantity' => $item->sum('quantity'),
                                ];
                            })
                            ->values();

                        $asksModelCache = Cache::get("asksModelCache.$market");
                        $asksModelCacheUpdated = Cache::get("asksModelCache.$market.updated");

                        if (!$asksModelCache || $asksModelCacheUpdated || $this->firstStart) {
                            Cache::put("asksModelCache.$market", $orderRepository->get($market, Order::SIDE_SELL));
                            Cache::put("asksModelCache.$market.updated", false);
                        }

                        $asksModelCache = Cache::get("asksModelCache.$market");

                        $asks = collect($asksModelCache->map(function ($item) use ($model) {
                            return [
                                'price' => $this->formatLocalOrderbookPrice((float)$item->price, $model),
                                'quantity' => $item->quantity,
                            ];
                        })->toArray())
                            ->merge(Cache::get("markets_liquidity.$market.asks"))
                            ->sortBy('price')
                            ->groupBy(['price'])
                            ->map(function ($item) use ($model) {
                                return [
                                    'price' => math_formatter($item->first()['price'], $model->quote_precision),
                                    'quantity' => $item->sum('quantity'),
                                ];
                            })
                            ->values();
                    }

                    $referenceBids=\App\Services\Market\PublicDepthSnapshot::plainPrices(Cache::get("markets_liquidity.$market.bids",[]),(int)$model->quote_precision);
                    $referenceAsks=\App\Services\Market\PublicDepthSnapshot::plainPrices(Cache::get("markets_liquidity.$market.asks",[]),(int)$model->quote_precision);
                    $sequence=is_array($data)?($data['u']??$data['lastUpdateId']??null):null;
                    Cache::put("markets_liquidity.$market.executable",[
                        'received_at'=>time(),'snapshot'=>hash('sha256',json_encode([$sequence,$referenceBids,$referenceAsks])),
                        'bids'=>$referenceBids,'asks'=>$referenceAsks,
                    ],30);
                    Cache::put("markets_liquidity.$market.received_at", time(), 30);
                    if ($market === 'UMI-USDT' && config('umi-v2.funded_enabled') && time() - $umiQuoteRecordedAt >= 5) {
                        $umiQuoteRecordedAt = time();
                        try { app(\App\Services\Umi\V2\BestAskQuote::class)->read(true); }
                        catch (\Throwable $error) { \Illuminate\Support\Facades\Log::warning('UMI best ask snapshot unavailable', ['type'=>get_class($error)]); }
                    }
                    $this->firstStart = false;

                    try {
                        event(new OrderBookSnapshot(
                            $market,
                            $bids,
                            $asks,
                            $model,
                        ));
                    } catch (\Exception $e) {
                        //
                    }
                }
            };
            $binanceApi->depthStream($marketName, $processDepth);

            // Quiet native books may not emit enough changes to renew the snapshot.
            // Ask the SAME provider for a real snapshot, never extend cached freshness.
            // Mapped/adjusted markets, including UMI, retain their original stream path.
            if (($marketName === market_sanitize($market) || \App\Services\Market\StablecoinOrientation::inverse($model)) && empty($model->bs) && !$model->custom_liquidity_t) {
                $snapshotTimer = \React\EventLoop\Loop::addPeriodicTimer(5, function () use ($processDepth, $marketName, $market, $binanceApi) {
                    if (!\App\Services\Market\PublicDepthSnapshot::due(Cache::get("markets_liquidity.$market.received_at"), time())) return;
                    try {
                        $snapshot=\App\Services\Market\PublicDepthSnapshot::fetch($marketName,$binanceApi->isOnTestnet());
                        $processDepth($snapshot,$marketName,$snapshot['bids'],$snapshot['asks'],true);
                    } catch (\Throwable $e) {
                        Log::warning('Public depth refresh unavailable', ['market'=>$market]);
                    }
                });
            }

            $marketsBuyTrades = 0;
            $marketsSellTrades = 0;

            $trigerredTime = 0;

            $binanceApi->tradeStream($marketName, function ($data, $symbol, $trade) use ($model, $market, $orderRepository, &$marketsBuyTrades, &$marketsSellTrades, &$trigerredTime) {
                if (\App\Services\Market\StablecoinOrientation::inverse($model)) {
                    $trade = clone $trade;
                    $trade->q = bcmul((string)$trade->p, (string)$trade->q, 18);
                    $trade->p = \App\Services\Market\StablecoinOrientation::reciprocal($trade->p);
                    $trade->m = !$trade->m;
                }
                $trigerredInitial = (int)($trade->T / 1000);

                if ($trigerredInitial != $trigerredTime && ($trigerredTime + 1) != $trigerredInitial) {
                    $customMultiplier = (float)Cache::get("market_custom_multiplier.{$market}", 1);

                    if ($customMultiplier <= 0) {
                        $customMultiplier = 1;
                    }

                    $trigerredTime = $trigerredInitial;

                    $overrideTradePrice = $this->consumeKlineAdjustmentTradePrice($model);

                    $tradeObject = (object)[];
                    $tradeObject->market = $model;
                    $tradeObject->order_side = $trade->m ? 'buy' : 'sell';
                    $tradePrice = $overrideTradePrice !== null
                        ? $overrideTradePrice
                        : $this->applyPercentOffsetToPrice((float)$trade->p, $customMultiplier, $model);
                    $tradePrice = $this->rebaseTradePriceToRuntimeLast($model, (float)$tradePrice);
                    $tradeObject->price = math_formatter($tradePrice, (int) ($model->quote_precision ?? 8));
                    $tradeObject->base_currency = $trade->q;
                    $tradeObject->created_at = Carbon::createFromTimestampMs($trade->T)
                        ->timezone(config('app.timezone'));

                    try {
                        event(new MarketTradeLiteUpdated($tradeObject, false));
                    } catch (\Exception $e) {
                        //
                    }
                }

                try {
                    if ($trade->m) {
                        $marketsBuyTrades++;
                    } else {
                        $marketsSellTrades++;
                    }

                    if (($marketsBuyTrades + $marketsSellTrades) >= 30) {
                        $buyPercentage = number_format($marketsBuyTrades / 30 * 100, 2, '.', '');
                        $sellPercentage = number_format($marketsSellTrades / 30 * 100, 2, '.', '');

                        event(new MarketTradePressureUpdated($model->name, $buyPercentage, $sellPercentage));

                        $marketsSellTrades = 0;
                        $marketsBuyTrades = 0;
                    }
                } catch (\Exception $e) {
                    //
                }
            });
        } catch (\Exception $e) {
            \React\EventLoop\Loop::cancelTimer($healthTimer);
            if ($snapshotTimer) \React\EventLoop\Loop::cancelTimer($snapshotTimer);
            $this->info("Restart the market liquidity on exception");
            Log::error($e);

            return $this->handle($market);
        }
    }

    protected function shouldProcessOrderbook(): bool
    {
        if ($this->firstStart) {
            $this->lastOrderbookProcessedAt = microtime(true);
            return true;
        }

        $now = microtime(true);
        $intervalMs = max(250, (int) env('MARKET_ORDERBOOK_INTERVAL_MS', 500));

        if ((($now - $this->lastOrderbookProcessedAt) * 1000) < $intervalMs) {
            return false;
        }

        $this->lastOrderbookProcessedAt = $now;

        return true;
    }

    /**
     * 获取外部行情订阅 symbol。
     *
     * 如果 market.chart_symbol 不为空，则使用 chart_symbol 获取外部行情。
     * 否则使用本地交易对名称。
     */
    protected function getExternalMarketSymbol(string $market, Market $model): string
    {
        $chartSymbol = trim((string)($model->chart_symbol ?? ''));

        if ($chartSymbol !== '') {
            return strtoupper(str_replace(['-', '/', '_', ' '], '', $chartSymbol));
        }

        return market_sanitize($market);
    }

    /**
     * 特殊市场价格倍数。
     *
     * 正数 market.bs 为价格倍率，包括 0 < bs < 1。
     */
    protected function getSpecialMarketPriceMultiplier(Market $model): float
    {
        return MarketPriceMultiplier::resolve($model->bs);
    }

    /**
     * 应用特殊市场价格倍数。
     */
    protected function applySpecialMarketPriceMultiplier(float $price, Market $model)
    {
        $multiplier = $this->getSpecialMarketPriceMultiplier($model);

        return math_formatter($price * $multiplier, $model->quote_precision);
    }

    protected function formatLocalOrderbookPrice(float $price, Market $model)
    {
        return math_formatter($price, $model->quote_precision);
    }

    /**
     * 获取当前真实盘口中间价。
     */
    protected function getLiveMiddlePrice(Collection $bids, Collection $asks, Market $model): float
    {
        $bestBid = isset($bids[0][0]) ? (float)$bids[0][0] : 0;
        $bestAsk = isset($asks[0][0]) ? (float)$asks[0][0] : 0;

        if ($bestBid > 0 && $bestAsk > 0) {
            return ($bestBid + $bestAsk) / 2;
        }

        if ($bestBid > 0) {
            return $bestBid;
        }

        if ($bestAsk > 0) {
            return $bestAsk;
        }

        return (float)(market_get_stats($model->id, 'last') ?? 0);
    }

    /**
     * 获取自定义行情百分比。
     * bot_price_ceiling 现在是百分比，不是固定价格或目标价。
     * 正数上浮，负数下浮。
     */
    protected function getCustomLiquidityPercent(Market $model): float
    {
        $config = $this->getKlineRuntimeConfig((int) $model->id);
        $percent = array_key_exists('bot_price_ceiling', $config)
            ? (float) $config['bot_price_ceiling']
            : (float) ($model->bot_price_ceiling ?? 0);

        if (is_nan($percent) || is_infinite($percent)) {
            return 0;
        }

        /**
         * 防止配置错误把价格打成负数。
         * -99.99 表示最多下浮到接近 0。
         */
        if ($percent <= -99.99) {
            $percent = -99.99;
        }

        return $percent;
    }

    protected function getCustomLiquidityActive(Market $model): bool
    {
        $config = $this->getKlineRuntimeConfig((int) $model->id);

        if (array_key_exists('custom_liquidity_t', $config)) {
            return filter_var($config['custom_liquidity_t'], FILTER_VALIDATE_BOOLEAN);
        }

        return (int) ($model->custom_liquidity_t ?? 0) === 1;
    }

    protected function getKlineRuntimeConfig(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $config = Cache::get('market_kline_runtime_config_' . $marketId, []);

        return is_array($config) ? $config : [];
    }

    protected function consumeKlineAdjustmentTradePrice(Market $model): ?float
    {
        $marketId = (int) $model->id;

        if ($marketId <= 0) {
            return null;
        }

        $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

        if (!$adjustedAt || $adjustedAt === $this->lastAppliedKlineTradeAdjustedAt) {
            return null;
        }

        $adjustedTimestamp = strtotime((string) $adjustedAt);

        if (!$adjustedTimestamp || (time() - $adjustedTimestamp) > 10) {
            $this->lastAppliedKlineTradeAdjustedAt = (string) $adjustedAt;
            return null;
        }

        $adjustedPrice = Cache::get('market_kline_adjusted_price_' . $marketId, []);
        $price = is_array($adjustedPrice)
            ? (float) ($adjustedPrice['price'] ?? 0)
            : 0;
        $runtimeConfig = $this->getKlineRuntimeConfig($marketId);

        if ($price <= 0 && isset($runtimeConfig['last'])) {
            $price = (float) $runtimeConfig['last'];
        }

        if ($price <= 0) {
            $price = (float) (market_get_stats($marketId, 'last') ?? 0);
        }

        $this->lastAppliedKlineTradeAdjustedAt = (string) $adjustedAt;

        return $price > 0 ? $price : null;
    }

    protected function rebaseTradePriceToRuntimeLast(Market $model, float $price): float
    {
        if ($price <= 0 || !$this->hasRecentKlineAdjustment($model)) {
            return $price;
        }

        $runtimeLast = (float)(market_get_stats((int)$model->id, 'last') ?? 0);

        return $runtimeLast > 0 ? $runtimeLast : $price;
    }

    protected function hasRecentKlineAdjustment(Market $model): bool
    {
        $marketId = (int)$model->id;

        if ($marketId <= 0) {
            return false;
        }

        $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

        if (!$adjustedAt) {
            return false;
        }

        $adjustedTimestamp = strtotime((string)$adjustedAt);

        return $adjustedTimestamp > 0 && (time() - $adjustedTimestamp) <= 86400;
    }

    /**
     * 按百分比计算自定义行情价格。
     * 例如：
     *  livePrice = 100, bot_price_ceiling = 2   => 102
     *  livePrice = 100, bot_price_ceiling = -2  => 98
     */
    protected function makePercentOffsetPrice(Market $model, float $livePrice, float $percent): float
    {
        $precision = (int)($model->quote_precision ?? 8);

        if ($livePrice <= 0) {
            return 0;
        }

        $multiplier = 1 + ($percent / 100);

        if ($multiplier <= 0) {
            $multiplier = 0.0001;
        }

        return (float)math_formatter($livePrice * $multiplier, $precision);
    }

    /**
     * 对盘口 / 成交价应用百分比偏移。
     *
     * 最终还会根据 market.bs 处理：
     * 如果 bs > 1，价格额外乘以 bs。
     */
    protected function applyPercentOffsetToPrice(float $price, float $multiplier, Market $model)
    {
        if ($multiplier <= 0) {
            $multiplier = 1;
        }

        $price = $price * $multiplier;

        return $this->applySpecialMarketPriceMultiplier($price, $model);
    }

    /**
     * 兼容清理旧版趋势缓存。
     */
    protected function resetCustomLiquidityTrendCaches(string $market): void
    {
        Cache::forget("market_custom_start_price.{$market}");
        Cache::forget("market_custom_last_price.{$market}");
        Cache::forget("market_custom_last_time.{$market}");
        Cache::forget("market_custom_pullback_until.{$market}");
        Cache::forget("market_custom_target_price.{$market}");
        Cache::forget("market_custom_direction.{$market}");
        Cache::forget("market_custom_finished_price.{$market}");
    }

    protected function easeLinearSoft(float $x): float
    {
        $x = $this->clamp($x, 0, 1);

        return ($x * 0.8) + ((1 - cos($x * pi())) / 2 * 0.2);
    }

    protected function randomFloat(float $min, float $max): float
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return $min + (($max - $min) * lcg_value());
    }

    protected function clamp(float $value, float $min, float $max): float
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return max($min, min($max, $value));
    }
}
