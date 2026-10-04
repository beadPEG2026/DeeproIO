<?php

namespace App\Console\Commands\Market;

use App\Events\MarketStatsUpdated;
use App\Events\MarketTradeUpdated;
use App\Events\OrderBookSnapshot;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use App\Repositories\Order\OrderRepository;
use App\Services\Market\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MarketCustomLiquidityCommand extends Command
{
    protected const KLINE_ADJUSTMENT_ANCHOR_SECONDS = 86400;

    protected const KLINE_ADJUSTMENT_TRADE_SUPPRESS_SECONDS = 30;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:custom-token-liquidity {market}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Realistic trading bot with configurable trends and natural price movements';

    protected bool $firstStart = true;
    protected float $currentPrice = 0;
    protected float $lastPrice = 0;  // The displayed last price (always within spread)
    protected float $momentum = 0;
    protected int $cycleCount = 0;
    protected float $volatilityMultiplier = 1.0;
    protected array $recentPrices = [];
    
    // Current spread tracking
    protected float $bestBid = 0;
    protected float $bestAsk = 0;
    
    // Market microstructure simulation
    protected float $buyPressure = 0.5;
    protected float $sellPressure = 0.5;
    protected int $trendCycleDuration = 0;
    protected int $currentTrendCycle = 0;
    protected ?string $lastAppliedKlineAdjustedAt = null;
    protected bool $justSyncedKlineAdjustment = false;
    protected float $lastOrderbookBroadcastAt = 0.0;
    protected float $lastStatsUpdatedAt = 0.0;
    protected float $lastStatsBroadcastAt = 0.0;
    protected float $lastMarketPersistedAt = 0.0;
    protected float $lastPriceTickCreatedAt = 0.0;

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
    public function handle($market = null)
    {
        if (!$market) {
            $market = $this->argument('market');
        }

        $model = Market::whereName($market)->first();

        if (!$model || !$model->custom_liquidity) {
            return 0;
        }

        $orderRepository = new OrderRepository();

        $this->applyKlineRuntimeConfigToMarket($model);

        // Initialize price from saved state or market last price
        $this->initializePrice($model);
        
        // Initialize momentum from saved state
        $this->momentum = (float) ($model->bot_momentum ?? 0);
        
        // Set random trend cycle duration (varies between 50-200 cycles)
        $this->trendCycleDuration = rand(50, 200);

        try {
            while (true) {
                // Refresh model to get latest settings
                $model->refresh();
                $this->applyKlineRuntimeConfigToMarket($model);
                $this->syncPriceFromKlineAdjustment($model);
                $this->syncPriceFromRuntimeLastWhenKlineAdjusted($model);
                $justSyncedKlineAdjustment = $this->justSyncedKlineAdjustment;
                
                if (!$model->custom_liquidity) {
                    $this->info("Custom liquidity disabled, stopping bot");
                    return 0;
                }

                $this->cycleCount++;
                
                // Simulate natural price movement
                if (!$justSyncedKlineAdjustment) {
                    $this->simulatePriceMovement($model);
                }
                
                // Generate realistic order book
                $orders = $this->generateRealisticOrderBook($model);
                
                // Split into asks and bids
                list($asks, $bids) = $this->splitOrderBook($orders, $model);

                // Cache order book data
                Cache::put("markets_liquidity.$market.asks", $asks);
                Cache::put("markets_liquidity.$market.asks_total", $asks->sum('quantity'));
                Cache::put("markets_liquidity.$market.bids_total", $bids->sum('quantity'));
                Cache::put("markets_liquidity.$market.bids", $bids);

                // Merge with real user orders
                $bids = $this->mergeWithRealOrders($orderRepository, $market, $model, Order::SIDE_BUY, $bids, 'bids');
                $asks = $this->mergeWithRealOrders($orderRepository, $market, $model, Order::SIDE_SELL, $asks, 'asks');

                // CRITICAL: Update best bid/ask and ensure last price is within spread
                $this->updateSpreadAndLastPrice($bids, $asks, $model);

                if ($this->shouldBroadcastOrderbook($justSyncedKlineAdjustment)) {
                    try {
                        event(new OrderBookSnapshot($market, $bids, $asks, $model));
                    } catch (\Exception $e) {
                        Log::error("OrderBook broadcast failed: " . $e->getMessage());
                    }
                }

                $this->firstStart = false;

                $suppressTrades = $this->isKlineAdjustmentTradeSuppressed($model);

                // Simulate visible trades with configured frequency (always within spread)
                // Returns true if a trade was created, false otherwise
                $tradeCreated = ($justSyncedKlineAdjustment || $suppressTrades)
                    ? false
                    : $this->simulateTrade($model, $bids, $asks);

                // Only create price tick if no visible trade was created this cycle
                // This avoids duplicate transactions and ensures chart always shows correct price
                if (!$justSyncedKlineAdjustment && !$suppressTrades && !$tradeCreated && $this->shouldCreatePriceTick()) {
                    $this->createPriceTick($model);
                }

                if ($justSyncedKlineAdjustment) {
                    $this->justSyncedKlineAdjustment = false;
                }

                // Save current state periodically (every 10 cycles)
                if ($this->cycleCount % 10 === 0) {
                    $this->saveState($model);
                }

                // Flexible sleep interval - random duration between min and max (in milliseconds)
                $sleepInterval = $this->calculateFlexibleSleepInterval($model);
                usleep($sleepInterval * 1000); // Convert ms to microseconds
            }
        } catch (\Exception $e) {
            $this->info("Restarting market liquidity on exception: " . $e->getMessage());
            Log::error($e);
            sleep(5);
            return $this->handle($market);
        }
    }

    /**
     * Initialize price from saved state or market data
     */
    protected function initializePrice(Market $model): void
    {
        if (!empty($model->bot_current_price) && (float) $model->bot_current_price > 0) {
            $this->currentPrice = (float) $model->bot_current_price;
        } elseif ((float) market_get_stats($model->id, 'last') > 0) {
            $this->currentPrice = (float) market_get_stats($model->id, 'last');
        } elseif (!empty($model->bot_price_floor) && !empty($model->bot_price_ceiling)) {
            // Start at midpoint of floor and ceiling
            $this->currentPrice = ((float) $model->bot_price_floor + (float) $model->bot_price_ceiling) / 2;
        } else {
            // Fallback to legacy fields
            $this->currentPrice = (float) ($model->custom_liquidity_start_price ?? 1);
        }
        
        $this->lastPrice = $this->currentPrice;
        $this->info("Starting price: " . $this->currentPrice);
    }

    protected function syncPriceFromKlineAdjustment(Market $model): void
    {
        $state = $this->getKlineAdjustmentState($model);
        $adjustedAt = $state['adjusted_at'] ?? null;

        if (!$adjustedAt || $adjustedAt === $this->lastAppliedKlineAdjustedAt) {
            return;
        }

        $targetPrice = (float) ($state['price'] ?? 0);

        if ($targetPrice <= 0) {
            return;
        }

        $this->currentPrice = $targetPrice;
        $this->lastPrice = $targetPrice;
        $this->momentum = 0;
        $this->recentPrices = [$targetPrice];
        $this->lastAppliedKlineAdjustedAt = $adjustedAt;
        $this->justSyncedKlineAdjustment = true;

        $model->forceFill([
            'bot_current_price' => (string) $targetPrice,
            'bot_momentum' => 0,
            'last' => math_formatter($targetPrice, (int) ($model->quote_precision ?? 8)),
        ]);
    }

    protected function syncPriceFromRuntimeLastWhenKlineAdjusted(Market $model): void
    {
        if (empty($this->getKlineAdjustmentState($model))) {
            return;
        }

        $runtimeLast = (float) market_get_stats((int) $model->id, 'last');

        if ($runtimeLast <= 0) {
            return;
        }

        $basePrice = $this->currentPrice > 0 ? $this->currentPrice : $runtimeLast;
        $diffRatio = abs($runtimeLast - $basePrice) / max($basePrice, 0.00000001);

        if ($diffRatio < 0.0002) {
            return;
        }

        $this->currentPrice = $runtimeLast;
        $this->lastPrice = $runtimeLast;
        $this->momentum = 0;
        $this->recentPrices[] = $runtimeLast;

        if (count($this->recentPrices) > 50) {
            array_shift($this->recentPrices);
        }
    }

    /**
     * Update the spread tracking and ensure last price is always within bid-ask spread
     * This is CRITICAL for realistic market display
     */
    protected function updateSpreadAndLastPrice(Collection $bids, Collection $asks, Market $model): void
    {
        // Get best bid (highest buy order)
        $this->bestBid = $bids->isNotEmpty() ? (float) $bids->first()['price'] : $this->currentPrice * 0.999;
        
        // Get best ask (lowest sell order)
        $this->bestAsk = $asks->isNotEmpty() ? (float) $asks->first()['price'] : $this->currentPrice * 1.001;
        
        // Ensure best bid is always less than best ask (sanity check)
        if ($this->bestBid >= $this->bestAsk) {
            $midPrice = ($this->bestBid + $this->bestAsk) / 2;
            $this->bestBid = $midPrice * 0.9995;
            $this->bestAsk = $midPrice * 1.0005;
        }
        
        // Calculate the last price - ALWAYS within the spread
        if ($this->justSyncedKlineAdjustment && $this->currentPrice > 0) {
            $this->lastPrice = $this->currentPrice;
        } else {
            $this->lastPrice = $this->calculateLastPriceWithinSpread($model);
        }
        
        // Immediately update market stats with the corrected last price
        $this->updateMarketLastPrice($model);
    }

    /**
     * Calculate last price that is always within the bid-ask spread
     */
    protected function calculateLastPriceWithinSpread(Market $model): float
    {
        $spreadMidpoint = ($this->bestBid + $this->bestAsk) / 2;
        $spreadRange = $this->bestAsk - $this->bestBid;
        
        // The last price should be within the spread, biased by market pressure
        // Buy pressure > 0.5 = price closer to ask (bullish)
        // Buy pressure < 0.5 = price closer to bid (bearish)
        $pressureBias = ($this->buyPressure - 0.5) * 0.8; // -0.4 to +0.4
        
        // Add small random variation within spread
        $randomOffset = ($this->gaussianRandom() * 0.1); // -10% to +10% of spread
        
        // Calculate position within spread (0 = at bid, 1 = at ask)
        $spreadPosition = 0.5 + $pressureBias + $randomOffset;
        $spreadPosition = max(0.05, min(0.95, $spreadPosition)); // Keep 5% away from edges
        
        $lastPrice = $this->bestBid + ($spreadRange * $spreadPosition);
        
        // Final safety clamp - MUST be within spread
        $lastPrice = max($this->bestBid + ($spreadRange * 0.01), $lastPrice);
        $lastPrice = min($this->bestAsk - ($spreadRange * 0.01), $lastPrice);
        
        return $lastPrice;
    }

    protected function throttleMs(string $envKey, int $default, int $minimum): int
    {
        return max($minimum, (int) env($envKey, $default));
    }

    protected function shouldRunInterval(float &$lastRunAt, int $intervalMs, bool $force = false): bool
    {
        $now = microtime(true);

        if ($force || $lastRunAt <= 0 || (($now - $lastRunAt) * 1000) >= $intervalMs) {
            $lastRunAt = $now;
            return true;
        }

        return false;
    }

    protected function shouldBroadcastOrderbook(bool $force = false): bool
    {
        return $this->shouldRunInterval(
            $this->lastOrderbookBroadcastAt,
            $this->throttleMs('MARKET_CUSTOM_ORDERBOOK_INTERVAL_MS', 750, 250),
            $force || $this->firstStart
        );
    }

    protected function shouldCreatePriceTick(): bool
    {
        return $this->shouldRunInterval(
            $this->lastPriceTickCreatedAt,
            $this->throttleMs('MARKET_CUSTOM_PRICE_TICK_INTERVAL_MS', 1000, 500)
        );
    }

    protected function persistMarketLastPrice(Market $model, string $formattedLastPrice, bool $force = false): void
    {
        if (!$this->shouldRunInterval(
            $this->lastMarketPersistedAt,
            $this->throttleMs('MARKET_CUSTOM_DB_INTERVAL_MS', 5000, 1000),
            $force
        )) {
            $model->forceFill(['last' => $formattedLastPrice]);
            return;
        }

        $model->timestamps = false;
        $model->update(['last' => $formattedLastPrice]);
        $model->timestamps = true;
    }

    protected function broadcastMarketStats(Market $model, bool $force = false): void
    {
        if (!$this->shouldRunInterval(
            $this->lastStatsBroadcastAt,
            $this->throttleMs('MARKET_CUSTOM_STATS_BROADCAST_INTERVAL_MS', 1000, 500),
            $force
        )) {
            return;
        }

        try {
            event(new MarketStatsUpdated($model));
        } catch (\Exception $e) {
            // Ignore broadcast errors
        }
    }

    /**
     * Update market last price in database and broadcast
     */
    protected function updateMarketLastPrice(Market $model): void
    {
        $formattedLastPrice = math_formatter($this->lastPrice, $model->quote_precision);
        $force = $this->firstStart || $this->justSyncedKlineAdjustment;

        if (!$this->shouldRunInterval(
            $this->lastStatsUpdatedAt,
            $this->throttleMs('MARKET_CUSTOM_STATS_INTERVAL_MS', 500, 250),
            $force
        )) {
            $model->forceFill(['last' => $formattedLastPrice]);
            return;
        }
        
        // Update market stats
        (new MarketService())->updateStatsForce($model->id, 'last', $formattedLastPrice);

        if ($this->isKlineAdjustmentTradeSuppressed($model)) {
            $model->forceFill(['last' => $formattedLastPrice]);
            return;
        }
        
        // Update model's last price
        $this->persistMarketLastPrice($model, $formattedLastPrice, $force);
        
        // Broadcast stats update
        $this->broadcastMarketStats($model, $force);
    }

    /**
     * Simulate realistic price movement using multiple factors
     */
    protected function simulatePriceMovement(Market $model): void
    {
        $trendDirection = $model->bot_trend_direction ?? 'sideways';
        $trendStrength = (float) ($model->bot_trend_strength ?? 0.5);
        $baseVolatility = (float) ($model->bot_volatility ?? 0.02);
        $burstChance = (float) ($model->bot_volatility_burst_chance ?? 0.05);
        $priceFloor = !empty($model->bot_price_floor) ? (float) $model->bot_price_floor : null;
        $priceCeiling = !empty($model->bot_price_ceiling) ? (float) $model->bot_price_ceiling : null;

        if (!empty($this->getKlineAdjustmentState($model))) {
            $priceFloor = null;
            $priceCeiling = null;
        }

        // 1. Calculate trend bias
        $trendBias = $this->calculateTrendBias($trendDirection, $trendStrength);

        // 2. Apply volatility with possible burst
        $volatility = $baseVolatility;
        if ($this->shouldTriggerVolatilityBurst($burstChance)) {
            $volatility *= rand(200, 500) / 100; // 2x to 5x volatility burst
            $this->volatilityMultiplier = $volatility / $baseVolatility;
        } else {
            $this->volatilityMultiplier = max(1, $this->volatilityMultiplier * 0.95); // Decay burst
        }

        // 3. Random walk component (Geometric Brownian Motion inspired)
        $randomComponent = $this->gaussianRandom() * $volatility;

        // 4. Mean reversion component (price tends to return to moving average)
        $meanReversionStrength = 0.1;
        $movingAverage = $this->calculateMovingAverage();
        $meanReversion = 0;
        if ($movingAverage > 0 && count($this->recentPrices) >= 10) {
            $deviation = ($movingAverage - $this->currentPrice) / $this->currentPrice;
            $meanReversion = $deviation * $meanReversionStrength;
        }

        // 5. Momentum component (price tends to continue in same direction)
        $momentumDecay = 0.85;
        $momentumInfluence = 0.3;
        
        // 6. Market pressure simulation
        $this->updateMarketPressure($trendDirection, $trendStrength);
        $pressureBias = ($this->buyPressure - $this->sellPressure) * 0.01;

        // 7. Combine all components
        $priceChange = $randomComponent + $trendBias + $meanReversion + ($this->momentum * $momentumInfluence) + $pressureBias;

        // 8. Update momentum
        $this->momentum = ($this->momentum * $momentumDecay) + ($priceChange * (1 - $momentumDecay));

        // 9. Apply price change
        $newPrice = $this->currentPrice * (1 + $priceChange);

        // 10. Apply boundary constraints with soft bounce
        $newPrice = $this->applyBoundaryConstraints($newPrice, $priceFloor, $priceCeiling);

        // 11. Ensure price is positive and reasonable
        $newPrice = max(0.00000001, $newPrice);

        // 12. Track recent prices for moving average
        $this->recentPrices[] = $newPrice;
        if (count($this->recentPrices) > 50) {
            array_shift($this->recentPrices);
        }

        $this->currentPrice = $newPrice;

        // Update trend cycle
        $this->currentTrendCycle++;
        if ($this->currentTrendCycle >= $this->trendCycleDuration) {
            $this->currentTrendCycle = 0;
            $this->trendCycleDuration = rand(50, 200);
        }
    }

    /**
     * Calculate trend bias based on direction and strength
     */
    protected function calculateTrendBias(string $direction, float $strength): float
    {
        $baseBias = match ($direction) {
            'uptrend' => 0.001,    // Base upward bias
            'downtrend' => -0.001, // Base downward bias
            'sideways' => 0,       // No bias
            default => 0,
        };

        // Apply strength multiplier (0 to 1 maps to 0x to 3x)
        $strengthMultiplier = $strength * 3;
        
        // Add some randomness to prevent perfectly linear trends
        $trendNoise = $this->gaussianRandom() * 0.0005;
        
        // Apply cycle-based trend waves (creates natural up/down phases within trend)
        $cyclePhase = sin(($this->currentTrendCycle / $this->trendCycleDuration) * M_PI * 2);
        $cycleInfluence = $cyclePhase * 0.0003;

        return ($baseBias * $strengthMultiplier) + $trendNoise + $cycleInfluence;
    }

    /**
     * Update simulated market pressure
     */
    protected function updateMarketPressure(string $trendDirection, float $trendStrength): void
    {
        // Random walk for pressure
        $pressureChange = $this->gaussianRandom() * 0.02;
        
        // Trend influence on pressure
        $trendInfluence = match ($trendDirection) {
            'uptrend' => 0.005 * $trendStrength,
            'downtrend' => -0.005 * $trendStrength,
            default => 0,
        };

        $this->buyPressure = max(0.2, min(0.8, $this->buyPressure + $pressureChange + $trendInfluence));
        $this->sellPressure = 1 - $this->buyPressure;
    }

    /**
     * Apply boundary constraints with soft bounce effect
     */
    protected function applyBoundaryConstraints(float $price, ?float $floor, ?float $ceiling): float
    {
        if ($floor !== null && $price < $floor) {
            // Soft bounce from floor
            $overshoot = $floor - $price;
            $bounceStrength = 0.5 + (rand(0, 50) / 100);
            $price = $floor + ($overshoot * $bounceStrength);
            $this->momentum = abs($this->momentum) * 0.5; // Reverse momentum
        }

        if ($ceiling !== null && $price > $ceiling) {
            // Soft bounce from ceiling
            $overshoot = $price - $ceiling;
            $bounceStrength = 0.5 + (rand(0, 50) / 100);
            $price = $ceiling - ($overshoot * $bounceStrength);
            $this->momentum = -abs($this->momentum) * 0.5; // Reverse momentum
        }

        return $price;
    }

    /**
     * Check if volatility burst should trigger
     */
    protected function shouldTriggerVolatilityBurst(float $chance): bool
    {
        return (rand(0, 1000) / 1000) < $chance;
    }

    /**
     * Calculate simple moving average of recent prices
     */
    protected function calculateMovingAverage(): float
    {
        if (empty($this->recentPrices)) {
            return $this->currentPrice;
        }
        return array_sum($this->recentPrices) / count($this->recentPrices);
    }

    /**
     * Generate Gaussian random number (Box-Muller transform)
     */
    protected function gaussianRandom(): float
    {
        static $hasSpare = false;
        static $spare;

        if ($hasSpare) {
            $hasSpare = false;
            return $spare;
        }

        $u = 0;
        $v = 0;
        $s = 0;

        do {
            $u = (rand() / getrandmax()) * 2 - 1;
            $v = (rand() / getrandmax()) * 2 - 1;
            $s = $u * $u + $v * $v;
        } while ($s >= 1 || $s == 0);

        $s = sqrt(-2 * log($s) / $s);
        $spare = $v * $s;
        $hasSpare = true;

        return $u * $s;
    }

    /**
     * Generate realistic order book around current price
     */
    protected function generateRealisticOrderBook(Market $model): array
    {
        $depth = (int) ($model->bot_orderbook_depth ?? 20);
        $spreadPercent = (float) ($model->bot_spread_percentage ?? 0.001);
        $minAmount = (float) ($model->custom_liquidity_start_amount ?? 0.1);
        $maxAmount = (float) ($model->custom_liquidity_end_amount ?? 10);
        
        $orders = [];
        $halfSpread = $spreadPercent / 2;

        // Generate asks (sell orders) - above current price
        $askBasePrice = $this->currentPrice * (1 + $halfSpread);
        for ($i = 0; $i < $depth; $i++) {
            // Exponential price distribution - orders cluster near the spread
            $priceLevel = $askBasePrice * (1 + $this->exponentialPriceStep($i, $depth));
            $quantity = $this->generateOrderQuantity($minAmount, $maxAmount, $i, $depth);
            
            $orders[] = [
                $priceLevel,
                $quantity,
                'ask'
            ];
        }

        // Generate bids (buy orders) - below current price
        $bidBasePrice = $this->currentPrice * (1 - $halfSpread);
        for ($i = 0; $i < $depth; $i++) {
            // Exponential price distribution - orders cluster near the spread
            $priceLevel = $bidBasePrice * (1 - $this->exponentialPriceStep($i, $depth));
            $quantity = $this->generateOrderQuantity($minAmount, $maxAmount, $i, $depth);
            
            $orders[] = [
                $priceLevel,
                $quantity,
                'bid'
            ];
        }

        return $orders;
    }

    /**
     * Calculate exponential price step for order book levels
     */
    protected function exponentialPriceStep(int $level, int $totalLevels): float
    {
        // Creates clustering near the spread with wider gaps further out
        $normalizedLevel = $level / $totalLevels;
        $baseStep = 0.0001; // 0.01% base step
        $maxStep = 0.005;   // 0.5% max step at furthest level
        
        // Exponential curve with some noise
        $step = $baseStep + ($maxStep - $baseStep) * pow($normalizedLevel, 1.5);
        $noise = 1 + ($this->gaussianRandom() * 0.1); // +/- 10% noise
        
        return $step * $level * max(0.5, $noise);
    }

    /**
     * Generate order quantity with realistic distribution
     */
    protected function generateOrderQuantity(float $min, float $max, int $level, int $totalLevels): float
    {
        // Orders closer to spread tend to be smaller, larger orders further out
        $sizeMultiplier = 0.5 + ($level / $totalLevels) * 1.5;
        
        // Add randomness
        $randomFactor = 0.3 + (rand(0, 140) / 100); // 0.3 to 1.7x
        
        // Occasional large order (whale simulation)
        $isWhale = rand(0, 100) < 5;
        if ($isWhale) {
            $randomFactor *= 3;
        }

        $baseQuantity = $min + (($max - $min) * (rand(0, 100) / 100));
        
        return $baseQuantity * $sizeMultiplier * $randomFactor;
    }

    /**
     * Split orders into asks and bids collections
     */
    protected function splitOrderBook(array $orders, Market $model): array
    {
        $asks = new Collection();
        $bids = new Collection();

        $askRatio = ($model->discount ?? 0) * 0.01;
        $bidRatio = ($model->discount_bid ?? 0) * 0.01;

        foreach ($orders as $order) {
            $price = $order[0];
            $quantity = $order[1];
            $side = $order[2];

            if ($side === 'ask') {
                $adjustedPrice = $price * (1 + $askRatio);
                $asks->push([
                    'price' => math_formatter($adjustedPrice, $model->quote_precision),
                    'quantity' => math_formatter($quantity, $model->base_precision),
                ]);
            } else {
                $adjustedPrice = $price * (1 + $bidRatio);
                $bids->push([
                    'price' => math_formatter($adjustedPrice, $model->quote_precision),
                    'quantity' => math_formatter($quantity, $model->base_precision),
                ]);
            }
        }

        // Sort asks ascending (lowest first), bids descending (highest first)
        $asks = $asks->sortBy('price')->values();
        $bids = $bids->sortByDesc('price')->values();

        return [$asks, $bids];
    }

    /**
     * Merge bot orders with real user orders
     */
    protected function mergeWithRealOrders(
        OrderRepository $orderRepository,
        string $market,
        Market $model,
        string $side,
        Collection $botOrders,
        string $cacheKey
    ): Collection {
        $cacheModelKey = ($side === Order::SIDE_BUY ? 'bidsModelCache' : 'asksModelCache') . ".$market";
        $cacheUpdatedKey = $cacheModelKey . '.updated';

        $modelCache = Cache::get($cacheModelKey);
        $cacheUpdated = Cache::get($cacheUpdatedKey);

        if (!$modelCache || $cacheUpdated || $this->firstStart) {
            Cache::put($cacheModelKey, $orderRepository->get($market, $side));
            Cache::put($cacheUpdatedKey, false);
        }

        $modelCache = Cache::get($cacheModelKey);

        $realOrders = $modelCache->map(function ($item) use ($model) {
            return [
                'price' => math_formatter($item->price, $model->quote_precision),
                'quantity' => $item->quantity,
            ];
        })->toArray();

        $merged = collect($realOrders)
            ->merge(Cache::get("markets_liquidity.$market.$cacheKey"))
            ->groupBy('price')
            ->map(function ($items) use ($model) {
                return [
                    'price' => math_formatter($items->first()['price'], $model->quote_precision),
                    'quantity' => $items->sum('quantity'),
                ];
            });

        if ($side === Order::SIDE_BUY) {
            return $merged->sortByDesc('price')->values();
        }

        return $merged->sortBy('price')->values();
    }

    /**
     * Create a price tick transaction to keep the chart in sync with current price.
     * This is called EVERY cycle to ensure the chart always reflects the current last price.
     */
    protected function createPriceTick(Market $model): void
    {
        if ($this->bestBid <= 0 || $this->bestAsk <= 0 || $this->lastPrice <= 0) {
            return;
        }

        // Use the current last price (which is always within spread)
        // Format as string for bcmath functions
        $tickPrice = number_format($this->lastPrice, $model->quote_precision, '.', '');
        
        $tickVolume = number_format($this->randomFakeTradeQuantity(), $model->base_precision, '.', '');
        
        // Calculate quote volume using bcmath-safe values
        $tickQuoteVolume = bcmul($tickPrice, $tickVolume, $model->quote_precision);

        // Make stats visible before broadcasting the trade, so chart/price/trade read the same tick.
        (new MarketService())->updateStats($model->id, $tickPrice, $tickVolume, $tickQuoteVolume);

        // Determine side based on price movement
        $isBuy = $this->momentum >= 0;

        $tickTransaction = [
            'is_maker' => true,
            'process_id' => generate_uuid(),
            'order_id' => null,
            'user_id' => null,
            'market_id' => $model->id,
            'order_type' => 'market',
            'order_side' => $isBuy ? 'buy' : 'sell',
            'fee' => 0,
            'referral_fee' => 0,
            'is_volume' => 0,
            'price' => $tickPrice,
            'base_currency' => $tickVolume,
            'quote_currency' => $tickQuoteVolume,
        ];

        // Create the tick transaction - this ensures chart candles update
        $transaction = (new Transaction())->create($tickTransaction);
        
        // Broadcast the trade update for real-time chart
        event(new MarketTradeUpdated($transaction, false));
    }

    /**
     * Simulate a visible trade with realistic behavior - ALWAYS within spread
     * These are larger trades that appear in trade history
     * 
     * @return bool True if a trade was created, false otherwise
     */
    protected function simulateTrade(Market $model, Collection $bids, Collection $asks): bool
    {
        $tradeFrequency = (int) ($model->bot_trade_frequency ?? 30);
        
        // Check if we should create a visible trade this cycle
        if (rand(1, 100) > $tradeFrequency || $bids->isEmpty() || $asks->isEmpty()) {
            return false;
        }

        // CRITICAL: Trade price MUST be within the spread
        $spreadRange = $this->bestAsk - $this->bestBid;
        
        // Determine trade side based on market pressure
        $isBuy = (rand(0, 100) / 100) < $this->buyPressure;
        
        // Calculate trade price within spread
        // Buy trades happen closer to ask, sell trades happen closer to bid
        if ($isBuy) {
            // Buy trade: price between midpoint and ask
            $minPos = 0.5;
            $maxPos = 0.95;
        } else {
            // Sell trade: price between bid and midpoint
            $minPos = 0.05;
            $maxPos = 0.5;
        }
        
        $spreadPosition = $minPos + (($maxPos - $minPos) * (rand(0, 100) / 100));
        $tradePrice = $this->bestBid + ($spreadRange * $spreadPosition);
        
        // Ensure trade price is strictly within spread
        $tradePrice = max($this->bestBid + ($spreadRange * 0.01), $tradePrice);
        $tradePrice = min($this->bestAsk - ($spreadRange * 0.01), $tradePrice);
        
        $pVol = $this->randomFakeTradeQuantity();
        
        // Format values as strings for bcmath functions
        $formattedPrice = number_format($tradePrice, $model->quote_precision, '.', '');
        $formattedVol = number_format($pVol, $model->base_precision, '.', '');
        $qVol = bcmul($formattedPrice, $formattedVol, $model->quote_precision);

        // Update last price before emitting the trade, otherwise the chart can read the old last.
        $this->lastPrice = $tradePrice;
        (new MarketService())->updateStats($model->id, $formattedPrice, $formattedVol, $qVol);

        $this->persistMarketLastPrice($model, math_formatter($tradePrice, $model->quote_precision));

        $cursorTransactions = [
            'is_maker' => true,
            'process_id' => generate_uuid(),
            'order_id' => null,
            'user_id' => null,
            'market_id' => $model->id,
            'order_type' => 'market',
            'order_side' => $isBuy ? 'buy' : 'sell',
            'fee' => 0,
            'referral_fee' => 0,
            'is_volume' => 0,
            'price' => $formattedPrice,
            'base_currency' => $formattedVol,
            'quote_currency' => $qVol,
        ];

        $cursorTransaction = (new Transaction())->create($cursorTransactions);
        event(new MarketTradeUpdated($cursorTransaction, false));
        $this->broadcastMarketStats($model);
        
        return true;
    }

    protected function randomFakeTradeQuantity(): float
    {
        return random_int(1000, 300000) / 100;
    }

    protected function applyKlineRuntimeConfigToMarket(Market $model): void
    {
        $config = $this->getKlineRuntimeConfig((int) $model->id);

        if (empty($config)) {
            return;
        }

        foreach (['bot_price_floor', 'bot_price_ceiling', 'custom_liquidity_t', 'last', 'bot_current_price', 'bot_momentum'] as $field) {
            if (array_key_exists($field, $config)) {
                $model->{$field} = $config[$field];
            }
        }
    }

    protected function getKlineRuntimeConfig(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $config = Cache::get('market_kline_runtime_config_' . $marketId, []);

        return is_array($config) ? $config : [];
    }

    protected function getKlineAdjustmentState(Market $model): array
    {
        $marketId = (int) $model->id;

        if ($marketId <= 0) {
            return [];
        }

        $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

        if (!$adjustedAt) {
            return [];
        }

        $adjustedTimestamp = strtotime((string) $adjustedAt);

        if (!$adjustedTimestamp || (time() - $adjustedTimestamp) > self::KLINE_ADJUSTMENT_ANCHOR_SECONDS) {
            return [];
        }

        $adjustedPrice = Cache::get('market_kline_adjusted_price_' . $marketId, []);
        $targetPrice = is_array($adjustedPrice)
            ? (float) ($adjustedPrice['price'] ?? 0)
            : 0;
        $runtimeConfig = Cache::get('market_kline_runtime_config_' . $marketId, []);

        if ($targetPrice <= 0 && is_array($runtimeConfig) && isset($runtimeConfig['last'])) {
            $targetPrice = (float) $runtimeConfig['last'];
        }

        if ($targetPrice <= 0) {
            $targetPrice = (float) (market_get_stats($marketId, 'last') ?? 0);
        }

        if ($targetPrice <= 0) {
            return [];
        }

        return [
            'adjusted_at' => (string) $adjustedAt,
            'timestamp' => $adjustedTimestamp,
            'price' => $targetPrice,
        ];
    }

    protected function isKlineAdjustmentTradeSuppressed(Market $model): bool
    {
        $state = $this->getKlineAdjustmentState($model);

        if (empty($state['timestamp'])) {
            return false;
        }

        return (time() - (int) $state['timestamp']) <= self::KLINE_ADJUSTMENT_TRADE_SUPPRESS_SECONDS;
    }

    /**
     * Save current bot state to database
     */
    protected function saveState(Market $model): void
    {
        if ($this->isKlineAdjustmentTradeSuppressed($model)) {
            return;
        }

        try {
            $model->timestamps = false;
            $model->update([
                'bot_current_price' => (string) $this->currentPrice,
                'bot_momentum' => $this->momentum,
                'last' => math_formatter($this->lastPrice, $model->quote_precision),
            ]);
            $model->timestamps = true;
        } catch (\Exception $e) {
            Log::warning("Failed to save bot state: " . $e->getMessage());
        }
    }

    /**
     * Calculate flexible sleep interval with randomization for natural timing
     * Returns interval in milliseconds
     */
    protected function calculateFlexibleSleepInterval(Market $model): int
    {
        // Get min/max from model, with fallbacks
        $minInterval = (int) ($model->bot_cycle_interval_min ?? 1000); // Default 1 second
        $maxInterval = (int) ($model->bot_cycle_interval_max ?? 5000); // Default 5 seconds
        
        // Fallback to legacy field if new fields are not set
        if ($minInterval <= 0 && $maxInterval <= 0) {
            $legacySeconds = (int) ($model->bot_cycle_interval ?? 2);
            return max(500, $legacySeconds * 1000); // Convert to ms, minimum 500ms
        }
        
        // Ensure min <= max
        if ($minInterval > $maxInterval) {
            $temp = $minInterval;
            $minInterval = $maxInterval;
            $maxInterval = $temp;
        }
        
        // Keep the bot responsive while avoiding tight loops under load.
        $minInterval = max(500, $minInterval);
        $maxInterval = max($minInterval, $maxInterval);
        
        // Add slight variation using weighted random for more natural distribution
        // This creates clustering around the middle with occasional fast/slow cycles
        $range = $maxInterval - $minInterval;
        
        if ($range <= 0) {
            return $minInterval;
        }
        
        // Use gaussian-like distribution for more natural timing
        // Most values cluster in the middle, with occasional extremes
        $random1 = rand(0, 1000) / 1000;
        $random2 = rand(0, 1000) / 1000;
        $gaussianish = ($random1 + $random2) / 2; // Simple approximation of normal distribution
        
        $interval = $minInterval + (int) ($range * $gaussianish);
        
        return $interval;
    }
}
