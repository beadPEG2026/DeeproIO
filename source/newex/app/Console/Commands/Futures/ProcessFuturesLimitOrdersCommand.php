<?php

namespace App\Console\Commands\Futures;

use App\Models\Order\FuturesContract;
use App\Repositories\Order\OrderRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessFuturesLimitOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'futures:limit-order-process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending Futures Limit orders';

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
            // Process pending Futures Limit orders
            FuturesContract::where('status', 'pending')
                ->where('type', FuturesContract::TYPE_LIMIT)
                ->with('market')
                ->oldest()
                ->chunk(50, function($limitOrders) {
                    foreach ($limitOrders as $order) {
                        try { DB::transaction(function() use ($order) {
                            $this->orderRepository->processFuturesLimitOrder($order);
                        }, DB_REPEAT_AFTER_DEADLOCK); }
                        catch (\Illuminate\Validation\ValidationException $e) { \Illuminate\Support\Facades\Log::warning('Limit activation awaiting verified price',['contract_id'=>$order->id]); }
                    }
                });

            sleep(2);
        }
    }
}
