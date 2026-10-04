<?php

namespace App\Console\Commands\PeerOrder;

use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PeerOrderWatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'peer-order:watcher';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch and cancel expired P2P orders';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('P2P Order Watcher started...');

        while (true) {
            try {
                $orderRepository = new PeerOrderRepository();

                // Cancel expired orders
                PeerOrder::select('*')
                    ->where('status', 'payment_pending')
                    ->whereRaw("(created_at + (timeframe||' min')::interval) < now()")
                    ->chunk(50, function ($orders) use ($orderRepository) {
                        foreach ($orders as $order) {
                            try {
                                DB::beginTransaction();

                                // Lock the order to prevent race conditions
                                $lockedOrder = PeerOrder::where('id', $order->id)
                                    ->where('status', 'payment_pending')
                                    ->lockForUpdate()
                                    ->first();

                                if (!$lockedOrder) {
                                    // Order already processed
                                    DB::rollBack();
                                    continue;
                                }

                                $orderRepository->cancel($lockedOrder, true, null, null, null, true);

                                DB::commit();

                                Log::info("P2P Order expired and cancelled: {$order->id}");

                            } catch (\Throwable $e) {
                                DB::rollBack();
                                Log::error("Failed to cancel expired P2P order {$order->id}: " . $e->getMessage());
                            }
                        }
                    });

            } catch (\Throwable $e) {
                Log::error("P2P Order Watcher error: " . $e->getMessage());
            }

            sleep(2);
        }
    }
}
