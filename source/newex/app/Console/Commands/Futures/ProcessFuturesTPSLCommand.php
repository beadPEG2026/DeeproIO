<?php

namespace App\Console\Commands\Futures;

use App\Models\Order\FuturesContract;
use App\Repositories\Order\OrderRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessFuturesTPSLCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'futures:tpsl-process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Take Profit and Stop Loss orders for Futures';

    /**
     * @var OrderRepository
     */
    private $orderRepository;

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
                        'referral_balance_domain',
                        'is_long',
                        'take_profit_price',
                        'stop_loss_price',
                    ])
                    ->where('status', 'active')
                    ->where(function($query) {
                        $query->whereNotNull('take_profit_price')
                              ->orWhereNotNull('stop_loss_price');
                    })
                    ->with(['market:id,name,chart_source,quote_precision','user:id,is_xn'])
                    ->chunkById(50, function($futures) use (&$marketPrices) {
                        foreach ($futures as $future) {
                            try { $this->processTPSL($future, $marketPrices); }
                            catch (\Illuminate\Validation\ValidationException $e) { Log::warning('TP/SL awaiting verified price',['contract_id'=>$future->id]); }
                        }
                    });
            } catch (\Throwable $e) {
                Log::error('Futures TP/SL watcher error: ' . $e->getMessage());
            }

            sleep(1); // Check every second
        }
    }

    /**
     * Process TP/SL for a futures position
     *
     * @param FuturesContract $future
     * @param array $marketPrices
     * @return void
     */
    private function processTPSL(FuturesContract $future, array &$marketPrices)
    {
        if (!$future->market) {
            return;
        }

        $marketId = $future->market_id.':'.$future->referral_balance_domain.':'.$future->user_id;

        if (!array_key_exists($marketId, $marketPrices)) {
            $rawPrice = app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($future)['price'];

            $marketPrices[$marketId] = is_numeric($rawPrice) && (float) $rawPrice > 0
                ? math_formatter($rawPrice, $future->market->quote_precision)
                : '0';
        }

        $marketPrice = $marketPrices[$marketId];
        
        if (!is_numeric($marketPrice) || (float) $marketPrice <= 0) {
            return;
        }

        $shouldClose = false;
        $closeReason = '';

        // Check Take Profit
        if ($future->take_profit_price && $future->take_profit_price > 0) {
            if ($future->is_long) {
                // Long position: TP triggered when market price >= TP price
                if (math_compare($marketPrice, $future->take_profit_price) >= 0) {
                    $shouldClose = true;
                    $closeReason = 'take_profit';
                }
            } else {
                // Short position: TP triggered when market price <= TP price
                if (math_compare($marketPrice, $future->take_profit_price) <= 0) {
                    $shouldClose = true;
                    $closeReason = 'take_profit';
                }
            }
        }

        // Check Stop Loss (only if TP hasn't triggered)
        if (!$shouldClose && $future->stop_loss_price && $future->stop_loss_price > 0) {
            if ($future->is_long) {
                // Long position: SL triggered when market price <= SL price
                if (math_compare($marketPrice, $future->stop_loss_price) <= 0) {
                    $shouldClose = true;
                    $closeReason = 'stop_loss';
                }
            } else {
                // Short position: SL triggered when market price >= SL price
                if (math_compare($marketPrice, $future->stop_loss_price) >= 0) {
                    $shouldClose = true;
                    $closeReason = 'stop_loss';
                }
            }
        }

        if ($shouldClose) {
            $this->closePosition($future, $marketPrice, $closeReason);
        }
    }

    /**
     * Close futures position due to TP/SL
     *
     * @param FuturesContract $future
     * @param float $marketPrice
     * @param string $reason
     * @return void
     */
    private function closePosition(FuturesContract $future, $marketPrice, $reason)
    {
        try {
            $result = $this->orderRepository->closeFuturesPositionAtPrice(
                (string) $future->id,
                $marketPrice,
                $reason
            );

            if (!($result['success'] ?? false)) {
                Log::warning("Futures position close via {$reason} failed", [
                    'future_id' => $future->id,
                    'user_id' => $future->user_id,
                    'market_price' => $marketPrice,
                    'message' => $result['message'] ?? null,
                ]);

                return;
            }

            Log::info("Futures position closed via {$reason}", [
                'future_id' => $future->id,
                'user_id' => $future->user_id,
                'market_price' => $marketPrice,
                'liquidated' => $result['liquidated'] ?? false,
            ]);

        } catch (\Exception $e) {
            Log::error("Error closing futures position via TP/SL", [
                'future_id' => $future->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
