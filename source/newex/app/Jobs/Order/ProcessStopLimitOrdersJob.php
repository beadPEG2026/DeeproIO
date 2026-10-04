<?php

namespace App\Jobs\Order;

use App\Models\Order\Order;
use App\Services\Order\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStopLimitOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $market;

    /**
     * Create a new job instance.
     *
     * @param int $market_id
     */
    public function __construct($market_id)
    {
        $this->afterCommit();
        $this->market = $market_id;
        $this->onQueue('{okrcoin}:orders');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(OrderService $orderService)
    {
        // Process stop limit orders (using dependency injection)
        $orderService->processStopLimitOrders($this->market);
    }
}
