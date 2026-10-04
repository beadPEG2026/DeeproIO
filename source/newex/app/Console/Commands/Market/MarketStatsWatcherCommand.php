<?php

namespace App\Console\Commands\Market;

use App\Services\Market\MarketPriceMultiplier;
use App\Events\MarketStatsLiteUpdated;
use App\Models\Market\Market;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\Market\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketStatsWatcherCommand extends Command
{
    protected $signature = 'market-watcher:stats';

    protected const KLINE_ADJUSTMENT_STATS_GUARD_SECONDS = 30;

    protected const KLINE_ADJUSTMENT_ANCHOR_SECONDS = 86400;

    protected const DATABASE_LAST_PRICE_SYNC_SECONDS = 60;

    protected $markets = [];

    protected $initialMarkets = [];

    protected $quoteMarkets = [];

    protected $discounts = [];

    protected $baseMarkets = [];

    protected $bsMultipliers = [];

    protected $customPriceMultipliers = [];

    protected $latestRawStats = [];

    protected $latestAdjustedStats = [];

    protected $latestStatsPayloads = [];

    protected $statsPushedAt = [];

    protected $statsPushMicrotime = [];

    protected $runtimeStatsUpdatedAt = [];

    protected $databaseLastPriceUpdatedAt = [];

    protected $databaseRuntimeConfigSyncedAt = 0;

    protected $lastRefreshed = 0;

    protected $lastConfigChangedAt = null;

    protected $description = 'Command description';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->prepareMarkets();

        $binanceApi = new BinanceApi();
        $marketService = new MarketService();
        $connectionStartedAt = time();

        $binanceApi->ticker(false, function ($data, $symbol, $ticker, $ws) use ($marketService, $connectionStartedAt) {
            if (time() - $connectionStartedAt >= 3600) {
                $ws->close();
                return;
            }

            /*
             * 每次收到 websocket 包时，先检测 markets 表是否改过。
             * 如果 bs / discount / bot_price_ceiling 改了，
             * 会立即刷新配置，并用最新配置重新计算缓存价格。
             */
            $this->ensureMarketConfigsFresh();

            $tickerSymbol = $this->normalizeExternalSymbol(
                (string)(
                    $this->getValueFromSource($ticker, 'symbol', '') ?:
                    $this->getValueFromSource($data, 's', '') ?:
                    $symbol
                )
            );

            $marketId = $tickerSymbol !== '' ? ($this->markets[$tickerSymbol] ?? null) : null;

            if (!$marketId) {
                $this->pushHeartbeatStats($marketService);
                return;
            }

            $marketName = $this->initialMarkets[$marketId] ?? null;

            if (!$marketName) {
                $this->pushHeartbeatStats($marketService);
                return;
            }

            $tickerClose = $this->extractNumericValue([$ticker, $data], [
                'close',
                'last',
                'lastPrice',
                'c',
            ]);

            $tickerHigh = $this->extractNumericValue([$ticker, $data], [
                'high',
                'highPrice',
                'h',
            ]);

            $tickerLow = $this->extractNumericValue([$ticker, $data], [
                'low',
                'lowPrice',
                'l',
            ]);

            $tickerVolume = $this->extractNumericValue([$ticker, $data], [
                'volume',
                'v',
            ]);

            $tickerQVolume = $this->extractNumericValue([$ticker, $data], [
                'qVolume',
                'quoteVolume',
                'q',
            ]);

            if ($tickerClose <= 0) {
                $this->pushHeartbeatStats($marketService);
                return;
            }

            $rawStats = [
                'open' => $this->extractNumericValue([$ticker, $data], ['open', 'openPrice', 'o']),
                'close' => $tickerClose,
                'high' => $tickerHigh > 0 ? $tickerHigh : $tickerClose,
                'low' => $tickerLow > 0 ? $tickerLow : $tickerClose,
                'volume' => $tickerVolume,
                'qVolume' => $tickerQVolume,
            ];

            if ($marketName === 'USDT-USDC') {
                try { $rawStats = \App\Services\Market\StablecoinOrientation::ticker($rawStats); }
                catch (\InvalidArgumentException $e) { return; }
            }
            app(\App\Services\Market\TickerFreshness::class)->received((int)$marketId,'binance:'. $tickerSymbol,$this->getValueFromSource($data,'E',null) ?: $this->getValueFromSource($ticker,'closeTime',null));
            $this->rememberLatestRawStats((int)$marketId, $rawStats);

            $guardedPayload = $this->buildRecentKlineAdjustmentPayload((int)$marketId, $rawStats);

            if (!empty($guardedPayload)) {
                $this->forceRuntimeStatsToRecentKlineAdjustment($marketService, (int)$marketId, $guardedPayload);
                $this->rememberLatestStatsPayload((int)$marketId, $guardedPayload);
                $this->pushStatsPayload((int)$marketId, $guardedPayload, true);
                $this->pushHeartbeatStats($marketService, (int)$marketId);
                return;
            }

            /*
             * 使用当前最新的 markets 配置重新计算价格。
             * 如果你刚改了 bs / 涨跌比例，这里会立即用新值。
             */
            $adjustedStats = $this->buildAdjustedStats((int)$marketId, $rawStats);

            if (empty($adjustedStats)) {
                $this->pushHeartbeatStats($marketService);
                return;
            }

            $this->rememberLatestAdjustedStats((int)$marketId, $adjustedStats);
            $this->updateMarketRuntimeStats($marketService, (int)$marketId, $adjustedStats);
            $this->cacheMarketLastPriceForDatabaseSync((int)$marketId, $adjustedStats['last']);

            $payload = $this->buildStatsPayload((int)$marketId, $adjustedStats);

            if (!empty($payload)) {
                $this->rememberLatestStatsPayload((int)$marketId, $payload);
                $this->pushStatsPayload((int)$marketId, $payload);
            }

            /*
             * 当前市场已经推送真实行情。
             * 其他市场如果没有新行情，也每秒补推一次缓存行情。
             * 补推前也会重新检测配置并重新计算价格。
             */
            $this->pushHeartbeatStats($marketService, (int)$marketId);
        });
    }

    protected function ensureMarketConfigsFresh(): void
    {
        $this->syncPendingRuntimeConfigToDatabase();

        $configChangedAt = Cache::get('market_stats_config_changed_at');

        if ($configChangedAt && $configChangedAt !== $this->lastConfigChangedAt) {
            $this->lastConfigChangedAt = $configChangedAt;
            $this->prepareMarkets();
            return;
        }

        /*
         * 1 秒刷新一次 markets 表配置。
         * 这样后台改 bs / discount / bot_price_ceiling 后，
         * 下一秒推送就会使用修改后的价格。
         */
        if ((time() - (int)$this->lastRefreshed) >= 1) {
            $this->prepareMarkets();
        }
    }

    protected function prepareMarkets(): void
    {
        $columns = [
            'id',
            'name',
            'chart_symbol',
            'discount',
            'quote_precision',
            'base_precision',
            'bs',
            'last',
            'custom_liquidity_t',
            'bot_price_ceiling',
        ];

        $marketModels = Market::query()
            ->where('status', true)
            ->where('custom_liquidity', false)
            ->where(function ($query) {
                $query->where('liq', true)
                    ->orWhere(function ($subQuery) {
                        $subQuery->whereNotNull('chart_symbol')
                            ->where('chart_symbol', '<>', '');
                    });
            })
            ->get($columns);

        $activeMarketIds = [];

        $this->markets = [];
        $this->initialMarkets = [];
        $this->discounts = [];
        $this->quoteMarkets = [];
        $this->baseMarkets = [];
        $this->bsMultipliers = [];
        $this->customPriceMultipliers = [];

        foreach ($marketModels as $market) {
            if (\App\Services\Market\StockAssets::supports($market->name)) continue;
            $market = $this->applyKlineRuntimeConfigToMarket($market);

            $externalSymbol = $this->getExternalMarketSymbol($market);

            if ($externalSymbol === '') {
                continue;
            }

            $marketId = (int)$market->id;
            $activeMarketIds[] = $marketId;

            $quotePrecision = (int)($market->quote_precision ?? 8);
            $basePrecision = (int)($market->base_precision ?? 8);
            $changePercent = $this->getChangePercentByMarketId((int)$market->id);
            $last = $this->decimalString(market_get_stats($marketId, 'last') ?? 0);

            $this->markets[$externalSymbol] = $marketId;
            $this->initialMarkets[$marketId] = $market->name;
            $this->discounts[$marketId] = (float)($market->discount ?? 0);
            $this->quoteMarkets[$marketId] = $quotePrecision;
            $this->baseMarkets[$marketId] = $basePrecision;
            $this->bsMultipliers[$marketId] = $this->getPriceMultiplierFromMarket($market);
            $this->customPriceMultipliers[$marketId] = $this->getCustomPriceMultiplierFromMarket($market);

            /*
             * 如果已经有外部原始行情，配置刷新后立即用新配置重算缓存价格。
             * 这样没有新成交时，也能推送修改后的价格。
             */
            if (!empty($this->latestRawStats[$marketId])) {
                $adjustedStats = $this->buildAdjustedStats($marketId, $this->latestRawStats[$marketId]);

                if (!empty($adjustedStats)) {
                    $this->rememberLatestAdjustedStats($marketId, $adjustedStats);

                    $payload = $this->buildStatsPayload($marketId, $adjustedStats);

                    if (!empty($payload)) {
                        $this->rememberLatestStatsPayload($marketId, $payload);
                    }
                }

                continue;
            }

            /*
             * 如果启动后还没收到外部行情，先用实时缓存 last 初始化兜底推送。
             */
            if ($last > 0 && empty($this->latestStatsPayloads[$marketId])) {
                $this->latestAdjustedStats[$marketId] = [
                    'last' => $last,
                    'high' => $last,
                    'low' => $last,
                    'volume' => 0,
                    'qVolume' => 0,
                    'change' => $changePercent,
                ];

                $this->latestStatsPayloads[$marketId] = [
                    'name' => $market->name,
                    'last' => $this->formatDecimal($last, $quotePrecision),
                    'high' => $this->formatDecimal($last, $quotePrecision),
                    'low' => $this->formatDecimal($last, $quotePrecision),
                    'volume' => $this->formatDecimal(0, $basePrecision),
                    'qVolume' => $this->formatDecimal(0, $basePrecision),
                    'change' => $changePercent,
                ];
            }


        }

        /*
         * 清理已经不再监听的市场缓存。
         */
        foreach (array_keys($this->latestStatsPayloads) as $marketId) {
            if (!in_array((int)$marketId, $activeMarketIds, true)) {
                unset(
                    $this->latestRawStats[$marketId],
                    $this->latestAdjustedStats[$marketId],
                    $this->latestStatsPayloads[$marketId],
                    $this->statsPushedAt[$marketId],
                    $this->databaseLastPriceUpdatedAt[$marketId]
                );
            }
        }

        $this->lastRefreshed = time();
    }

    protected function rememberLatestRawStats(int $marketId, array $rawStats): void
    {
        if ($marketId <= 0) {
            return;
        }

        $this->latestRawStats[$marketId] = $rawStats;
    }

    protected function rememberLatestAdjustedStats(int $marketId, array $adjustedStats): void
    {
        if ($marketId <= 0) {
            return;
        }

        $this->latestAdjustedStats[$marketId] = $adjustedStats;
    }

    protected function rememberLatestStatsPayload(int $marketId, array $payload): void
    {
        if ($marketId <= 0) {
            return;
        }

        $this->latestStatsPayloads[$marketId] = $payload;
    }

    protected function buildAdjustedStats(int $marketId, array $rawStats): array
    {
        $rawClose = $this->decimalString($rawStats['close'] ?? 0);

        if ($rawClose <= 0) {
            return [];
        }

        $rawHigh = $this->decimalString($rawStats['high'] ?? $rawClose);
        $rawLow = $this->decimalString($rawStats['low'] ?? $rawClose);

        if ($rawHigh <= 0) {
            $rawHigh = $rawClose;
        }

        if ($rawLow <= 0) {
            $rawLow = $rawClose;
        }

        $standardStats = $this->buildStandardAdjustedStats($marketId, $rawStats, $rawClose, $rawHigh, $rawLow);
        $anchoredStats = $this->buildAnchoredKlineAdjustmentStats($marketId, $rawStats, $standardStats);

        if (!empty($anchoredStats)) {
            return $anchoredStats;
        }

        return $standardStats;
    }

    protected function buildStandardAdjustedStats(int $marketId, array $rawStats, string $rawClose, string $rawHigh, string $rawLow): array
    {
        $discount = (float)($this->discounts[$marketId] ?? 0);
        $discountRatio = $discount * 0.01;

        $priceMultiplier = $this->getPriceMultiplierByMarketId($marketId);
        $customPriceMultiplier = $this->getCustomPriceMultiplierByMarketId($marketId);

        $adjustedCloseRaw = $this->calculateRawAdjustedPrice($rawClose, $discountRatio, $customPriceMultiplier);
        $adjustedHighRaw = $this->calculateRawAdjustedPrice($rawHigh, $discountRatio, $customPriceMultiplier);
        $adjustedLowRaw = $this->calculateRawAdjustedPrice($rawLow, $discountRatio, $customPriceMultiplier);

        $close = $this->applyPriceMultiplier($adjustedCloseRaw, $priceMultiplier);
        $high = $this->applyPriceMultiplier($adjustedHighRaw, $priceMultiplier);
        $low = $this->applyPriceMultiplier($adjustedLowRaw, $priceMultiplier);

        $rawOpen = $this->decimalString($rawStats['open'] ?? 0);
        $open = $rawOpen > 0 ? $this->applyPriceMultiplier($this->calculateRawAdjustedPrice($rawOpen, $discountRatio, $customPriceMultiplier), $priceMultiplier) : null;

        $safeHigh = max($high, $low, $close);
        $safeLow = min($high, $low, $close);

        return [
            'last' => $close,
            'open' => $open,
            'high' => $safeHigh,
            'low' => $safeLow,
            'volume' => $this->decimalString($rawStats['volume'] ?? 0),
            'qVolume' => $this->decimalString($rawStats['qVolume'] ?? 0),
            'change' => $this->calculateChangePercent($close, $open),
        ];
    }

    protected function buildStatsPayload(int $marketId, array $adjustedStats): array
    {
        $marketName = $this->initialMarkets[$marketId] ?? null;

        if (!$marketName) {
            return [];
        }

        return [
            'name' => $marketName,
            'last' => $this->formatDecimal($adjustedStats['last'] ?? 0, $this->quoteMarkets[$marketId] ?? 8),
            'high' => $this->formatDecimal($adjustedStats['high'] ?? 0, $this->quoteMarkets[$marketId] ?? 8),
            'low' => $this->formatDecimal($adjustedStats['low'] ?? 0, $this->quoteMarkets[$marketId] ?? 8),
            'volume' => $this->formatDecimal($adjustedStats['volume'] ?? 0, $this->baseMarkets[$marketId] ?? 8),
            'qVolume' => $this->formatDecimal($adjustedStats['qVolume'] ?? 0, $this->baseMarkets[$marketId] ?? 8),
            'change' => $adjustedStats['change'] ?? null,
            ...app(\App\Services\Market\TickerFreshness::class)->snapshot($marketId),
        ];
    }

    protected function updateMarketRuntimeStats(MarketService $marketService, int $marketId, array $adjustedStats): void
    {
        if ($marketId <= 0 || empty($adjustedStats)) {
            return;
        }

        if (!$this->shouldRunPerMarketInterval(
            $this->runtimeStatsUpdatedAt,
            $marketId,
            max(200, (int) env('MARKET_STATS_RUNTIME_WRITE_INTERVAL_MS', 500))
        )) {
            return;
        }

        try {
            $marketService->updateStatsForce($marketId, 'high', $adjustedStats['high'] ?? 0);
            $marketService->updateStatsForce($marketId, 'low', $adjustedStats['low'] ?? 0);
            $marketService->updateStatsForce($marketId, 'volume', $adjustedStats['volume'] ?? 0);
            $marketService->updateStatsForce($marketId, 'last', $adjustedStats['last'] ?? 0);
            $marketService->updateStatsForce($marketId, 'qVolume', $adjustedStats['qVolume'] ?? 0);
            $marketService->updateStatsForce($marketId, 'change', $adjustedStats['change'] ?? null);
            Cache::put('market_stats_change_basis_' . $marketId, [
                'open' => $adjustedStats['open'] ?? null, 'last' => $adjustedStats['last'],
                'change' => $adjustedStats['change'] ?? null, 'updated_at' => now()->toIso8601String(),
            ], 120);
        } catch (\Exception $e) {
            //
        }
    }

    protected function pushStatsPayload(int $marketId, array $payload, bool $force = false): void
    {
        if ($marketId <= 0 || empty($payload)) {
            return;
        }

        if (!$force && !$this->shouldRunPerMarketInterval(
            $this->statsPushMicrotime,
            $marketId,
            max(100, (int) env('MARKET_STATS_PUSH_INTERVAL_MS', 300))
        )) {
            return;
        }

        try {
            event(new MarketStatsLiteUpdated($payload));
            $this->statsPushedAt[$marketId] = time();
        } catch (\Exception $e) {
            //
        }
    }

    /*
     * 心跳补推：
     * 如果没有新的 ticker，也每秒推一次。
     * 推送前会先刷新配置，并用最新配置重新计算价格。
     */
    protected function pushHeartbeatStats(MarketService $marketService, ?int $exceptMarketId = null): void
    {
        $this->ensureMarketConfigsFresh();

        $now = time();
        $heartbeatInterval = max(1, (int) env('MARKET_STATS_HEARTBEAT_SECONDS', 2));

        foreach ($this->latestStatsPayloads as $marketId => $payload) {
            $marketId = (int)$marketId;

            if ($exceptMarketId !== null && $marketId === (int)$exceptMarketId) {
                continue;
            }

            $lastPushedAt = (int)($this->statsPushedAt[$marketId] ?? 0);

            if (($now - $lastPushedAt) < $heartbeatInterval) {
                continue;
            }

            $guardedPayload = $this->buildRecentKlineAdjustmentPayload($marketId, $this->latestRawStats[$marketId] ?? null);

            if (!empty($guardedPayload)) {
                $this->forceRuntimeStatsToRecentKlineAdjustment($marketService, $marketId, $guardedPayload);
                $this->rememberLatestStatsPayload($marketId, $guardedPayload);
                $this->pushStatsPayload($marketId, $guardedPayload, true);
                continue;
            }

            /*
             * 如果有原始行情，用最新配置重新算。
             * 这样改 bs / 涨跌比例后，没有新成交也能推新价格。
             */
            if (!empty($this->latestRawStats[$marketId])) {
                $adjustedStats = $this->buildAdjustedStats($marketId, $this->latestRawStats[$marketId]);

                if (!empty($adjustedStats)) {
                    $this->rememberLatestAdjustedStats($marketId, $adjustedStats);
                    $this->updateMarketRuntimeStats($marketService, $marketId, $adjustedStats);
                    $this->cacheMarketLastPriceForDatabaseSync($marketId, $adjustedStats['last']);

                    $payload = $this->buildStatsPayload($marketId, $adjustedStats);
                    $this->rememberLatestStatsPayload($marketId, $payload);
                }
            }

            if (empty($payload)) {
                $payload = $this->latestStatsPayloads[$marketId] ?? [];
            }

            if (empty($payload)) {
                continue;
            }

            $this->pushStatsPayload($marketId, $payload);
        }
    }

    protected function buildRecentKlineAdjustmentPayload(int $marketId, ?array $rawStats = null): array
    {
        $state = $this->getKlineAdjustmentState($marketId);

        if (empty($state)) {
            return [];
        }

        if ((time() - (int)$state['timestamp']) > self::KLINE_ADJUSTMENT_STATS_GUARD_SECONDS) {
            return [];
        }

        $this->rememberKlineAdjustmentAnchor($marketId, $rawStats, $state);

        $price = $this->decimalString($state['price']);

        if ($price <= 0) {
            return [];
        }

        $previousPayload = $this->latestStatsPayloads[$marketId] ?? [];
        $quotePrecision = (int)($this->quoteMarkets[$marketId] ?? ($state['quote_precision'] ?? 8));
        $basePrecision = (int)($this->baseMarkets[$marketId] ?? 8);

        return [
            'name' => $this->initialMarkets[$marketId] ?? '',
            'last' => $this->formatDecimal($price, $quotePrecision),
            'high' => $this->formatDecimal($price, $quotePrecision),
            'low' => $this->formatDecimal($price, $quotePrecision),
            'volume' => $previousPayload['volume'] ?? $this->formatDecimal(0, $basePrecision),
            'qVolume' => $previousPayload['qVolume'] ?? $this->formatDecimal(0, $basePrecision),
            'change' => $previousPayload['change'] ?? $this->getChangePercentByMarketId($marketId),
            ...app(\App\Services\Market\TickerFreshness::class)->snapshot($marketId),
        ];
    }

    protected function getKlineAdjustmentState(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

        if (!$adjustedAt) {
            return [];
        }

        $adjustedTimestamp = strtotime((string)$adjustedAt);

        if (!$adjustedTimestamp || (time() - $adjustedTimestamp) > self::KLINE_ADJUSTMENT_ANCHOR_SECONDS) {
            return [];
        }

        $adjustedPrice = Cache::get('market_kline_adjusted_price_' . $marketId, []);
        $price = $this->decimalString(is_array($adjustedPrice) ? ($adjustedPrice['price'] ?? 0) : 0);
        $runtimeConfig = $this->getKlineRuntimeConfig($marketId);

        if ($price <= 0 && isset($runtimeConfig['last'])) {
            $price = $this->decimalString($runtimeConfig['last']);
        }

        if ($price <= 0) {
            $price = $this->decimalString(market_get_stats($marketId, 'last') ?? 0);
        }

        if ($price <= 0) {
            return [];
        }

        return [
            'adjusted_at' => (string)$adjustedAt,
            'timestamp' => $adjustedTimestamp,
            'price' => $price,
            'quote_precision' => is_array($adjustedPrice) ? ($adjustedPrice['quote_precision'] ?? null) : null,
        ];
    }

    protected function buildAnchoredKlineAdjustmentStats(int $marketId, array $rawStats, array $standardStats): array
    {
        $state = $this->getKlineAdjustmentState($marketId);

        if (empty($state) || (time() - (int)$state['timestamp']) <= self::KLINE_ADJUSTMENT_STATS_GUARD_SECONDS) {
            return [];
        }

        $this->rememberKlineAdjustmentAnchor($marketId, $rawStats, $state, $standardStats);

        $anchor = Cache::get($this->getKlineAdjustmentAnchorCacheKey($marketId), []);

        if (
            !is_array($anchor) ||
            ($anchor['adjusted_at'] ?? null) !== $state['adjusted_at'] ||
            (float)($anchor['standard_close'] ?? 0) <= 0
        ) {
            return [];
        }

        $anchorPrice = $this->decimalString($anchor['price']);
        $anchorStandardClose = $this->decimalString($anchor['standard_close']);
        $offset = bcsub($anchorPrice, $anchorStandardClose, math_scale());

        $close = math_sum($this->decimalString($standardStats['last'] ?? 0), $offset);
        $high = math_sum($this->decimalString($standardStats['high'] ?? 0), $offset);
        $low = math_sum($this->decimalString($standardStats['low'] ?? 0), $offset);

        if ($close <= 0) {
            $close = $anchorPrice;
        }

        $open = isset($standardStats['open']) ? math_sum($this->decimalString($standardStats['open']), $offset) : null;
        $safeHigh = max($high, $low, $close);
        $safeLow = min($high, $low, $close);

        return [
            'last' => $close,
            'open' => $open,
            'high' => $safeHigh,
            'low' => $safeLow,
            'volume' => $this->decimalString($standardStats['volume'] ?? ($rawStats['volume'] ?? 0)),
            'qVolume' => $this->decimalString($standardStats['qVolume'] ?? ($rawStats['qVolume'] ?? 0)),
            'change' => $this->calculateChangePercent($close, $open),
        ];
    }

    protected function rememberKlineAdjustmentAnchor(int $marketId, ?array $rawStats, array $state, ?array $standardStats = null): void
    {
        if ($marketId <= 0 || empty($rawStats)) {
            return;
        }

        $rawClose = $this->decimalString($rawStats['close'] ?? 0);

        if ($rawClose <= 0) {
            return;
        }

        $cacheKey = $this->getKlineAdjustmentAnchorCacheKey($marketId);
        $existing = Cache::get($cacheKey, []);

        if (empty($standardStats)) {
            $rawHigh = $this->decimalString($rawStats['high'] ?? $rawClose);
            $rawLow = $this->decimalString($rawStats['low'] ?? $rawClose);
            $standardStats = $this->buildStandardAdjustedStats($marketId, $rawStats, $rawClose, $rawHigh > 0 ? $rawHigh : $rawClose, $rawLow > 0 ? $rawLow : $rawClose);
        }

        if (is_array($existing) && ($existing['adjusted_at'] ?? null) === $state['adjusted_at'] && (float)($existing['standard_close'] ?? 0) > 0) {
            return;
        }

        Cache::put($cacheKey, [
            'adjusted_at' => $state['adjusted_at'],
            'price' => $this->decimalString($state['price']),
            'raw_close' => $rawClose,
            'raw_high' => $this->decimalString($rawStats['high'] ?? $rawClose),
            'raw_low' => $this->decimalString($rawStats['low'] ?? $rawClose),
            'standard_close' => $this->decimalString($standardStats['last'] ?? 0),
            'standard_high' => $this->decimalString($standardStats['high'] ?? 0),
            'standard_low' => $this->decimalString($standardStats['low'] ?? 0),
            'created_at' => now()->toIso8601String(),
        ], now()->addSeconds(self::KLINE_ADJUSTMENT_ANCHOR_SECONDS));
    }

    protected function getKlineAdjustmentAnchorCacheKey(int $marketId): string
    {
        return 'market_kline_adjustment_anchor_' . $marketId;
    }

    protected function forceRuntimeStatsToRecentKlineAdjustment(MarketService $marketService, int $marketId, array $payload): void
    {
        if ($marketId <= 0 || empty($payload['last'])) {
            return;
        }

        $price = $this->decimalString($payload['last']);

        if ($price <= 0) {
            return;
        }

        try {
            $marketService->updateStatsForce($marketId, 'last', $price);
            $marketService->updateStatsForce($marketId, 'high', $price);
            $marketService->updateStatsForce($marketId, 'low', $price);
            $marketService->updateStatsForce($marketId, 'change', $payload['change'] ?? null);
        } catch (\Throwable $e) {
            //
        }
    }

    protected function getExternalMarketSymbol(Market $market): string
    {
        $chartSymbol = trim((string)($market->chart_symbol ?? ''));

        if ($chartSymbol !== '') {
            return $this->normalizeExternalSymbol($chartSymbol);
        }

        return $this->normalizeExternalSymbol(market_sanitize($market->name));
    }

    protected function normalizeExternalSymbol(string $symbol): string
    {
        $symbol = trim($symbol);

        if ($symbol === '') {
            return '';
        }

        return strtoupper(str_replace(['-', '/', '_', ' '], '', $symbol));
    }

    protected function getPriceMultiplierFromMarket(Market $market): float
    {
        return MarketPriceMultiplier::resolve($market->bs);
    }

    protected function getPriceMultiplierByMarketId($marketId): float
    {
        $marketId = (int)$marketId;

        return (float)($this->bsMultipliers[$marketId] ?? 1);
    }

    protected function getChangePercentByMarketId($marketId): ?string
    {
        return $this->latestAdjustedStats[(int)$marketId]['change'] ?? null;
    }

    protected function calculateChangePercent(float $close, ?float $open): ?string
    {
        if (!$open || $open <= 0 || $close <= 0 || !is_finite($open) || !is_finite($close)) return null;
        return $this->formatDecimal(($close - $open) / $open * 100, 2);
    }

    protected function getCustomPriceMultiplierFromMarket(Market $market): float
    {
        $market = $this->applyKlineRuntimeConfigToMarket($market);

        if ((int)($market->custom_liquidity_t ?? 0) !== 1) {
            return 1;
        }

        $percent = (float)($market->bot_price_ceiling ?? 0);

        if (is_nan($percent) || is_infinite($percent)) {
            return 1;
        }

        if (abs($percent) <= 0.0000000001) {
            return 1;
        }

        if ($percent <= -99.99) {
            $percent = -99.99;
        }

        $multiplier = 1 + ($percent / 100);

        if ($multiplier <= 0) {
            return 0.0001;
        }

        return $multiplier;
    }

    protected function getCustomPriceMultiplierByMarketId($marketId): float
    {
        $marketId = (int)$marketId;

        $multiplier = (float)($this->customPriceMultipliers[$marketId] ?? 1);

        if ($multiplier <= 0) {
            return 1;
        }

        return $multiplier;
    }

    /** Keep feed decimals intact; BCMath and math_formatter do not accept exponent notation. */
    protected function decimalString($value): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^([+-]?)([0-9]*\.?[0-9]+|[0-9]+\.)(?:[eE]([+-]?[0-9]+))?$/', $value, $parts)) {
            return '0';
        }

        $exponent = (int)($parts[3] ?? 0);
        if (abs($exponent) > 1000) {
            return '0';
        }
        $mantissa = explode('.', $parts[2]);
        $digits = $mantissa[0] . ($mantissa[1] ?? '');
        $point = strlen($mantissa[0]) + $exponent;
        if ($point <= 0) {
            $decimal = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $decimal = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $decimal = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        $decimal = ltrim($decimal, '0');
        if (strpos($decimal, '.') !== false) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }
        if ($decimal === '') {
            return '0';
        }
        if ($decimal[0] === '.') {
            $decimal = '0' . $decimal;
        }
        return ($parts[1] === '-' ? '-' : '') . $decimal;
    }

    protected function formatDecimal($value, int $precision): string
    {
        return math_formatter($this->decimalString($value), $precision);
    }

    protected function calculateRawAdjustedPrice($rawPrice, float $discountRatio, float $customPriceMultiplier): string
    {
        $rawPrice = $this->decimalString($rawPrice);
        if ($rawPrice <= 0) {
            return '0';
        }

        if (abs($customPriceMultiplier - 1) > 0.0000000001) {
            return math_multiply($rawPrice, $this->decimalString($customPriceMultiplier));
        }

        return math_sum($rawPrice, math_multiply($rawPrice, $this->decimalString($discountRatio)));
    }

    protected function applyPriceMultiplier($price, float $priceMultiplier): string
    {
        $price = $this->decimalString($price);
        if ($price <= 0) {
            return '0';
        }

        return math_multiply($price, $this->decimalString(MarketPriceMultiplier::resolve($priceMultiplier)));
    }

    protected function extractNumericValue(array $sources, array $keys, float $default = 0): string
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = $this->getValueFromSource($source, $key, null);

                if ($value === null || $value === '') {
                    continue;
                }

                $value = trim((string)$value);
                $value = str_replace(['%', ','], '', $value);

                if (!is_numeric($value)) {
                    continue;
                }

                return $this->decimalString($value);
            }
        }

        return $this->decimalString($default);
    }

    protected function getValueFromSource($source, string $key, $default = null)
    {
        if (is_array($source)) {
            return $source[$key] ?? $default;
        }

        if (is_object($source)) {
            return $source->{$key} ?? $default;
        }

        return $default;
    }

    protected function cacheMarketLastPriceForDatabaseSync(int $marketId, $lastPrice): void
    {
        if ($marketId <= 0 || $lastPrice <= 0) {
            return;
        }

        $now = time();
        $lastUpdatedAt = (int)($this->databaseLastPriceUpdatedAt[$marketId] ?? 0);
        $syncInterval = max(1, (int) env('MARKET_STATS_RUNTIME_CACHE_SECONDS', 5));

        if (($now - $lastUpdatedAt) < $syncInterval) {
            return;
        }

        $this->putKlineRuntimeConfig($marketId, [
            'last' => $this->formatDecimal($lastPrice, $this->quoteMarkets[$marketId] ?? 8),
        ]);

        $this->databaseLastPriceUpdatedAt[$marketId] = $now;
        $this->syncPendingRuntimeConfigToDatabase();
    }

    protected function shouldRunPerMarketInterval(array &$timestamps, int $marketId, int $intervalMs): bool
    {
        if ($marketId <= 0) {
            return false;
        }

        $now = microtime(true);
        $lastRunAt = (float)($timestamps[$marketId] ?? 0);

        if ($lastRunAt > 0 && (($now - $lastRunAt) * 1000) < $intervalMs) {
            return false;
        }

        $timestamps[$marketId] = $now;

        return true;
    }

    protected function getKlineRuntimeConfigCacheKey(int $marketId): string
    {
        return 'market_kline_runtime_config_' . $marketId;
    }

    protected function getKlineRuntimeConfigIdsCacheKey(): string
    {
        return 'market_kline_runtime_config_ids';
    }

    protected function getKlineRuntimeConfig(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $config = Cache::get($this->getKlineRuntimeConfigCacheKey($marketId), []);

        return is_array($config) ? $config : [];
    }

    protected function putKlineRuntimeConfig(int $marketId, array $data): void
    {
        if ($marketId <= 0) {
            return;
        }

        $config = $this->getKlineRuntimeConfig($marketId);
        $persistPending = !empty($config['persist_pending']);
        $shouldPersist = $this->hasRuntimeConfigDatabaseFields($data);

        foreach ($data as $key => $value) {
            $config[$key] = $value;
        }

        $config['market_id'] = $marketId;
        if (array_key_exists('persist_pending', $data)) {
            $config['persist_pending'] = (bool) $data['persist_pending'];
        } elseif ($persistPending || $shouldPersist) {
            $config['persist_pending'] = true;
        }

        if ($shouldPersist && empty($data['persist_after']) && !$persistPending) {
            $config['persist_after'] = time() + self::DATABASE_LAST_PRICE_SYNC_SECONDS;
        }

        $config['updated_at'] = now()->toDateTimeString();

        Cache::forever($this->getKlineRuntimeConfigCacheKey($marketId), $config);

        $ids = Cache::get($this->getKlineRuntimeConfigIdsCacheKey(), []);
        $ids = is_array($ids) ? $ids : [];
        $ids[] = $marketId;
        $ids = array_values(array_unique(array_map('intval', $ids)));

        Cache::forever($this->getKlineRuntimeConfigIdsCacheKey(), $ids);
    }

    protected function hasRuntimeConfigDatabaseFields(array $data): bool
    {
        foreach (['bot_price_floor', 'bot_price_ceiling', 'custom_liquidity_t', 'last', 'bot_current_price', 'bot_momentum'] as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    protected function applyKlineRuntimeConfigToMarket(Market $market): Market
    {
        $config = $this->getKlineRuntimeConfig((int) $market->id);

        if (empty($config)) {
            return $market;
        }

        foreach (['bot_price_floor', 'bot_price_ceiling', 'custom_liquidity_t', 'last', 'bot_current_price', 'bot_momentum'] as $field) {
            if (array_key_exists($field, $config)) {
                $market->{$field} = $config[$field];
            }
        }

        return $market;
    }

    protected function syncPendingRuntimeConfigToDatabase(bool $force = false): void
    {
        $now = time();

        if (!$force && ($now - (int) $this->databaseRuntimeConfigSyncedAt) < 1) {
            return;
        }

        $this->databaseRuntimeConfigSyncedAt = $now;

        $ids = Cache::get($this->getKlineRuntimeConfigIdsCacheKey(), []);

        if (!is_array($ids) || empty($ids)) {
            return;
        }

        foreach (array_values(array_unique(array_map('intval', $ids))) as $marketId) {
            if ($marketId <= 0) {
                continue;
            }

            $config = $this->getKlineRuntimeConfig($marketId);

            if (empty($config) || empty($config['persist_pending'])) {
                continue;
            }

            $persistAfter = (int)($config['persist_after'] ?? 0);

            if (!$force && $persistAfter > 0 && $persistAfter > $now) {
                continue;
            }

            $update = [];

            if (array_key_exists('bot_price_floor', $config)) {
                $update['bot_price_floor'] = $config['bot_price_floor'];
            }

            if (array_key_exists('bot_price_ceiling', $config)) {
                $update['bot_price_ceiling'] = $config['bot_price_ceiling'];
            }

            if (array_key_exists('custom_liquidity_t', $config)) {
                $update['custom_liquidity_t'] = filter_var($config['custom_liquidity_t'], FILTER_VALIDATE_BOOLEAN);
            }

            if (array_key_exists('last', $config) && is_numeric($config['last']) && (float) $config['last'] > 0) {
                $update['last'] = $config['last'];
            }

            if (array_key_exists('bot_current_price', $config) && is_numeric($config['bot_current_price']) && (float) $config['bot_current_price'] > 0) {
                $update['bot_current_price'] = (string)$config['bot_current_price'];
            }

            if (array_key_exists('bot_momentum', $config)) {
                $update['bot_momentum'] = $config['bot_momentum'];
            }

            if (empty($update)) {
                continue;
            }

            $update['updated_at'] = now();

            try {
                DB::table('markets')
                    ->where('id', $marketId)
                    ->update($update);

                $config['persist_pending'] = false;
                $config['persisted_at'] = now()->toDateTimeString();
                unset($config['persist_after']);
                Cache::forever($this->getKlineRuntimeConfigCacheKey($marketId), $config);
            } catch (\Exception $e) {
                //
            }
        }
    }

}
