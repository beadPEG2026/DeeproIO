<?php

namespace App\Console\Commands\PeerOrder;

use App\Modules\P2P\Mail\Orders\AppealReceivedAdmin;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class PeerOrderAppealWatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'peer-order-appeal:watcher';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch for P2P appeals and notify admin';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $processed = 0;
        $errors = 0;

        // Find appealed orders that haven't been notified yet
        PeerOrder::where('status', 'appealed_by_counterparty')
            ->where('appeal_notified', false)
            ->where('created_at', '<', Carbon::now()->subMinutes(10))
            ->chunk(50, function ($orders) use (&$processed, &$errors) {
                foreach ($orders as $order) {
                    try {
                        DB::beginTransaction();

                        // Lock the order to prevent double notification
                        $lockedOrder = PeerOrder::where('id', $order->id)
                            ->where('appeal_notified', false)
                            ->lockForUpdate()
                            ->first();

                        if (!$lockedOrder) {
                            // Already processed
                            DB::rollBack();
                            continue;
                        }

                        // Admin Email Notification
                        $adminEmail = Setting::get('notification.admin_email', false);

                        if ($adminEmail) {
                            Mail::to($adminEmail)->queue(new AppealReceivedAdmin([
                                'order_id' => $lockedOrder->id,
                                'created_at' => $lockedOrder->created_at
                            ]));
                        }

                        $lockedOrder->appeal_notified = true;
                        $lockedOrder->save();

                        DB::commit();
                        $processed++;

                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("Failed to process P2P appeal notification for order {$order->id}: " . $e->getMessage());
                        $errors++;
                    }
                }
            });

        if ($processed > 0 || $errors > 0) {
            $this->info("Processed: {$processed}, Errors: {$errors}");
        }

        return $errors > 0 ? 1 : 0;
    }
}
