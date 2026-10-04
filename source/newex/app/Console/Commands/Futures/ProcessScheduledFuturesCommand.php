<?php

namespace App\Console\Commands\Futures;

use App\Models\Order\FuturesContract;
use App\Repositories\Order\OrderRepository;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledFuturesCommand extends Command
{
    protected $signature = 'futures:scheduled';

    protected $description = 'Continuously process scheduled futures: activate when due and auto-close when timeframe ends';

    private $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        parent::__construct();
        $this->orderRepository = $orderRepository;
    }

    public function handle(): int
    {
        $this->info('Futures processor started. Press Ctrl+C to stop.');

        while (true) {

            try {
                $now = Carbon::now();

                // 1) Activate due scheduled orders
                $due = FuturesContract::where('status', 'scheduled')
                    ->whereNotNull('start_at')
                    ->where('start_at', '<=', $now)
                    ->limit(200)
                    ->get();

                foreach ($due as $order) {
                    try {
                        $order->status = 'active';
                        $order->scheduled_status = 'started';
                        $order->activated_at = $now;
                        $order->save();
                    } catch (\Throwable $e) {
                        Log::error($e);
                    }
                }

                // 2) Auto-close active orders whose timeframe elapsed
                $activeToClose = FuturesContract::with(['market', 'user'])
                    ->where('status', 'active')
                    ->where('timeframe_seconds', '>', 0)
                    ->whereNotNull('activated_at')
                    ->whereRaw('EXTRACT(EPOCH FROM (NOW() - activated_at)) >= timeframe_seconds')
                    ->limit(200)
                    ->get();

                foreach ($activeToClose as $order) {
                    try {
                        if (!$order->market) {
                            continue;
                        }

                        $marketPrice = math_formatter(
                            app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($order)['price'],
                            $order->market->quote_precision
                        );

                        if (!$marketPrice || $marketPrice <= 0) {
                            continue;
                        }

                        $result = $this->orderRepository->closeFuturesPositionAtPrice(
                            (string) $order->id,
                            $marketPrice,
                            'timeframe'
                        );

                        if (!($result['success'] ?? false)) {
                            Log::warning('Scheduled futures close failed', [
                                'future_id' => $order->id,
                                'user_id' => $order->user_id,
                                'market_price' => $marketPrice,
                                'message' => $result['message'] ?? null,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error($e);
                    }
                }

            } catch (\Throwable $e) {
                Log::error($e);
            }

            // Sleep ~1s before next iteration
            usleep(1000000);
        }

        // unreachable
        // return 0;
    }
}
