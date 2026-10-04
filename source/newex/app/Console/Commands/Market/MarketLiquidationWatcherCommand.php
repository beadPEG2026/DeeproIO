<?php

namespace App\Console\Commands\Market;

use App\Events\MarketStatsLiteUpdated;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Repositories\Order\OrderRepository;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\Market\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketLiquidationWatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-watcher:liquidation';

    protected $markets;

    protected $initialMarkets;

    protected $lastMarkets;

    protected $quoteMarkets;

    protected $baseMarkets;

    protected $lastRefreshed;

    protected $iteration = 0;

    protected $orderRepository;

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
    public function __construct(OrderRepository $orderRepository)
    {
        parent::__construct();
        $this->orderRepository = $orderRepository;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        while(true) {
            $marketPrices = [];

            try {
                FuturesContract::query()
                    ->select([
                        'id',
                        'market_id',
                        'user_id',
                        'liquidation_price',
                        'is_long',
                        'balance',
                    ])
                    ->where('status', 'active')
                    ->chunkById(100, function($futures) use (&$marketPrices) {
                        foreach ($futures as $future) {
                            $this->processLiquidation($future, $marketPrices);
                        }
                    });
            } catch (\Throwable $e) {
                Log::error('Liquidation watcher error: ' . $e->getMessage());
            }

            sleep(2);
        }
    }

    /**
     * Process liquidation for a single futures position
     *
     * @param FuturesContract $future
     * @param array $marketPrices
     * @return void
     */
    private function processLiquidation(FuturesContract $future, array &$marketPrices)
    {
        try {
            $marketId = (int) $future->market_id;

            if (!array_key_exists($marketId, $marketPrices)) {
                $marketPrices[$marketId] = market_get_stats($marketId, 'last');
            }

            $marketPrice = $marketPrices[$marketId];

            if (!is_numeric($marketPrice) || (float) $marketPrice <= 0) {
                return;
            }

            $shouldLiquidate = false;

            if ($future->is_long) {
                // Long position: liquidate when market price <= liquidation price
                if (math_compare($marketPrice, $future->liquidation_price) <= 0) {
                    $shouldLiquidate = true;
                }
            } else {
                // Short position: liquidate when market price >= liquidation price
                if (math_compare($marketPrice, $future->liquidation_price) >= 0) {
                    $shouldLiquidate = true;
                }
            }

            if ($shouldLiquidate) {
                $result = $this->orderRepository->forceLiquidateFutures((string) $future->id);

                if (!($result['success'] ?? false)) {
                    Log::warning("Futures position liquidation failed", [
                        'future_id' => $future->id,
                        'user_id' => $future->user_id,
                        'market_price' => $marketPrice,
                        'liquidation_price' => $future->liquidation_price,
                        'message' => $result['message'] ?? null,
                    ]);

                    return;
                }

                Log::info("Futures position liquidated", [
                    'future_id' => $future->id,
                    'user_id' => $future->user_id,
                    'market_price' => $marketPrice,
                    'liquidation_price' => $future->liquidation_price,
                    'is_long' => $future->is_long,
                    'margin_lost' => $future->balance
                ]);
            }

        } catch (\Throwable $e) {
            Log::error("Error processing liquidation for future {$future->id}: " . $e->getMessage());
        }
    }
}
