<?php

namespace App\Console\Commands\Market;

use App\Events\MarketStatsLiteUpdated;
use App\Models\Market\Market;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\Liquidity\Mexc\MexcApi;
use App\Services\Market\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketMexcStatsWatcherCommand extends Command
{
    protected const KLINE_ADJUSTMENT_STATS_GUARD_SECONDS = 30;

    protected const KLINE_ADJUSTMENT_ANCHOR_SECONDS = 86400;

    protected const DATABASE_LAST_PRICE_SYNC_SECONDS = 60;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-watcher:stats-mexc';

    protected $markets;

    protected $initialMarkets;

    protected $lastMarkets;

    protected $quoteMarkets;

    protected $discounts;

    protected $baseMarkets;

    protected $lastRefreshed;

    protected $iteration = 0;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->prepareMarkets();

        $binanceApi = new MexcApi();

        $marketService = new MarketService();

        //try {

        $binanceApi->ticker(false, function ($data, $symbol, $ticker) use ($marketService) {

            $marketName = $this->markets[$ticker['symbol']] ?? false;
            if ($marketName) {
                $marketId = (int)$this->markets[$ticker['symbol']];
                app(\App\Services\Market\TickerFreshness::class)->received($marketId,'mexc:'.$ticker['symbol'],$ticker['closeTime']??$data['E']??null);
                $rawStats = [
                    'close' => (float)($ticker['close'] ?? 0),
                    'high' => (float)($ticker['high'] ?? ($ticker['close'] ?? 0)),
                    'low' => (float)($ticker['low'] ?? ($ticker['close'] ?? 0)),
                    'volume' => (float)($ticker['volume'] ?? 0),
                    'qVolume' => (float)($ticker['qVolume'] ?? 0),
                ];

                $cj = Cache::get("market_custom_cj.{$ticker['symbol']}", 0);
                $discount = $this->discounts[$marketId];
                $ratio = $discount * 0.01;
                $standardPrices = [
                    'close' => $ticker['close'] + ($ticker['close'] * $ratio) - $cj,
                    'high' => $ticker['high'] + ($ticker['high'] * $ratio) - $cj,
                    'low' => $ticker['low'] + ($ticker['low'] * $ratio) - $cj,
                ];

                $guardedPayload = $this->buildRecentKlineAdjustmentPayload($marketId, $rawStats, $standardPrices);

                if (!empty($guardedPayload)) {
                    $this->forceRuntimeStatsToRecentKlineAdjustment($marketService, $marketId, $guardedPayload);
                    event(new MarketStatsLiteUpdated($guardedPayload));

                    if ((time() - $this->lastRefreshed) > 60) {
                        $this->prepareMarkets();
                    }

                    return;
                }

                $anchoredPrices = $this->buildAnchoredKlineAdjustmentPrices($marketId, $rawStats, $standardPrices);

                if (!empty($anchoredPrices)) {
                    $close = $anchoredPrices['close'];
                    $high = $anchoredPrices['high'];
                    $low = $anchoredPrices['low'];
                } else {
                    $close = $standardPrices['close'];
                    $high = $standardPrices['high'];
                    $low = $standardPrices['low'];
                }
            
                $marketService->updateStatsForce($marketId, 'high', $high);
                $marketService->updateStatsForce($marketId, 'low', $low);
                $marketService->updateStatsForce($marketId, 'volume', $ticker['volume']);
                $marketService->updateStatsForce($marketId, 'last', $close);
                $marketService->updateStatsForce($marketId, 'qVolume', $ticker['qVolume']);
                $this->putKlineRuntimeConfig($marketId, [
                    'last' => math_formatter($close, $this->quoteMarkets[$marketId] ?? 8),
                    'bot_current_price' => (string)$close,
                    'bot_momentum' => 0,
                ]);

                event(new MarketStatsLiteUpdated([
                    'name' => $this->initialMarkets[$marketId],
                    'last' => math_formatter($close, $this->quoteMarkets[$marketId]),
                    'high' => math_formatter($high, $this->quoteMarkets[$marketId]),
                    'low' => math_formatter($low, $this->quoteMarkets[$marketId]),
                    'volume' => math_formatter($ticker['volume'], $this->baseMarkets[$marketId]),
                    'qVolume' => math_formatter($ticker['qVolume'], $this->baseMarkets[$marketId]),
                    'change' => math_percentage_between($close, $this->lastMarkets[$marketId]),
                    ...app(\App\Services\Market\TickerFreshness::class)->snapshot($marketId),
                ]));

                if ((time() - $this->lastRefreshed) > 60) {
                    $this->prepareMarkets();
                }
            }
        });
    }

    protected function prepareMarkets() {

        $marketList = Market::where('liq', true)->pluck('name');

        $this->markets = Market::whereIn('name', $marketList)->pluck('id', 'name')->toArray();
        $this->discounts = Market::whereIn('name', $marketList)->pluck('discount', 'id')->toArray();
        $this->lastMarkets = Market::whereIn('name', $marketList)->pluck('last', 'id')->toArray();
        $this->quoteMarkets = Market::whereIn('name', $marketList)->pluck('quote_precision', 'id')->toArray();
        $this->baseMarkets = Market::whereIn('name', $marketList)->pluck('base_precision', 'id')->toArray();

        foreach($this->markets as $market=>$id) {
            unset($this->markets[$market]);
            $this->markets[market_sanitize($market)] = $id;
            $this->initialMarkets[$id] = $market;
        }

        $this->lastRefreshed = time();

    }

    protected function buildRecentKlineAdjustmentPayload(int $marketId, ?array $rawStats = null, ?array $standardPrices = null): array
    {
        $state = $this->getKlineAdjustmentState($marketId);

        if (empty($state)) {
            return [];
        }

        if ((time() - (int)$state['timestamp']) > self::KLINE_ADJUSTMENT_STATS_GUARD_SECONDS) {
            return [];
        }

        $this->rememberKlineAdjustmentAnchor($marketId, $rawStats, $state, $standardPrices);

        $price = (float)$state['price'];

        if ($price <= 0) {
            return [];
        }

        $quotePrecision = (int)($this->quoteMarkets[$marketId] ?? ($state['quote_precision'] ?? 8));
        $basePrecision = (int)($this->baseMarkets[$marketId] ?? 8);

        return [
            'name' => $this->initialMarkets[$marketId] ?? '',
            'last' => math_formatter($price, $quotePrecision),
            'high' => math_formatter($price, $quotePrecision),
            'low' => math_formatter($price, $quotePrecision),
            'volume' => math_formatter(0, $basePrecision),
            'qVolume' => math_formatter(0, $basePrecision),
            'change' => isset($this->lastMarkets[$marketId])
                ? math_percentage_between($price, $this->lastMarkets[$marketId])
                : math_formatter(0, 2),
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
        $price = is_array($adjustedPrice) ? (float)($adjustedPrice['price'] ?? 0) : 0;
        $runtimeConfig = Cache::get('market_kline_runtime_config_' . $marketId, []);

        if ($price <= 0 && is_array($runtimeConfig) && isset($runtimeConfig['last'])) {
            $price = (float)$runtimeConfig['last'];
        }

        if ($price <= 0) {
            $price = (float)(market_get_stats($marketId, 'last') ?? 0);
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

    protected function buildAnchoredKlineAdjustmentPrices(int $marketId, array $rawStats, array $standardPrices): array
    {
        $state = $this->getKlineAdjustmentState($marketId);

        if (empty($state) || (time() - (int)$state['timestamp']) <= self::KLINE_ADJUSTMENT_STATS_GUARD_SECONDS) {
            return [];
        }

        $this->rememberKlineAdjustmentAnchor($marketId, $rawStats, $state, $standardPrices);

        $anchor = Cache::get($this->getKlineAdjustmentAnchorCacheKey($marketId), []);

        if (
            !is_array($anchor) ||
            ($anchor['adjusted_at'] ?? null) !== $state['adjusted_at'] ||
            (float)($anchor['standard_close'] ?? 0) <= 0
        ) {
            return [];
        }

        $anchorPrice = (float)$anchor['price'];
        $anchorStandardClose = (float)$anchor['standard_close'];
        $offset = $anchorPrice - $anchorStandardClose;
        $standardClose = (float)($standardPrices['close'] ?? 0);

        if ($standardClose <= 0) {
            return [];
        }

        $close = $standardClose + $offset;
        $high = (float)($standardPrices['high'] ?? $standardClose) + $offset;
        $low = (float)($standardPrices['low'] ?? $standardClose) + $offset;

        if ($close <= 0) {
            $close = $anchorPrice;
        }

        return [
            'close' => $close,
            'high' => max($high, $low, $close),
            'low' => min($high, $low, $close),
        ];
    }

    protected function rememberKlineAdjustmentAnchor(int $marketId, ?array $rawStats, array $state, ?array $standardPrices = null): void
    {
        if ($marketId <= 0 || empty($rawStats)) {
            return;
        }

        $rawClose = (float)($rawStats['close'] ?? 0);

        if ($rawClose <= 0) {
            return;
        }

        $cacheKey = $this->getKlineAdjustmentAnchorCacheKey($marketId);
        $existing = Cache::get($cacheKey, []);

        if (empty($standardPrices)) {
            $standardPrices = [
                'close' => $rawClose,
                'high' => (float)($rawStats['high'] ?? $rawClose),
                'low' => (float)($rawStats['low'] ?? $rawClose),
            ];
        }

        if (is_array($existing) && ($existing['adjusted_at'] ?? null) === $state['adjusted_at'] && (float)($existing['standard_close'] ?? 0) > 0) {
            return;
        }

        Cache::put($cacheKey, [
            'adjusted_at' => $state['adjusted_at'],
            'price' => (float)$state['price'],
            'raw_close' => $rawClose,
            'raw_high' => (float)($rawStats['high'] ?? $rawClose),
            'raw_low' => (float)($rawStats['low'] ?? $rawClose),
            'standard_close' => (float)($standardPrices['close'] ?? 0),
            'standard_high' => (float)($standardPrices['high'] ?? 0),
            'standard_low' => (float)($standardPrices['low'] ?? 0),
            'created_at' => now()->toIso8601String(),
        ], now()->addSeconds(self::KLINE_ADJUSTMENT_ANCHOR_SECONDS));
    }

    protected function getKlineAdjustmentAnchorCacheKey(int $marketId): string
    {
        return 'market_kline_adjustment_anchor_' . $marketId;
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

    protected function forceRuntimeStatsToRecentKlineAdjustment(MarketService $marketService, int $marketId, array $payload): void
    {
        if ($marketId <= 0 || empty($payload['last'])) {
            return;
        }

        $price = (float)$payload['last'];

        if ($price <= 0) {
            return;
        }

        try {
            $marketService->updateStatsForce($marketId, 'last', $price);
            $marketService->updateStatsForce($marketId, 'high', $price);
            $marketService->updateStatsForce($marketId, 'low', $price);
        } catch (\Throwable $e) {
            //
        }
    }
}
