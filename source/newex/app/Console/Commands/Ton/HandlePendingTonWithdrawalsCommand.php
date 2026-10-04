<?php

namespace App\Console\Commands\Ton;

use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HandlePendingTonWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ton:withdrawals-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending TON withdrawals and update balances';

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
        // Find withdrawals waiting for provider approval that have a transaction hash
        $withdrawals = Withdrawal::where(function($query) {
            $query->where('status', WITHDRAWAL_WAITING_PROVIDER_APPROVAL)
                  ->whereNotNull('txn');
        })->where('network_id', NETWORK_TON)->get();

        $tonService = new TonService();
        $processed = 0;

        foreach ($withdrawals as $withdrawal) {
            try {
                // If we have a txn hash, mark as confirmed
                if ($withdrawal->txn) {
                    $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                    $withdrawal->save();
                }

                // Handle balance updates and notifications
                $tonService->handleWithdraw($withdrawal);
                $processed++;

                $this->info("Processed TON withdrawal ID: {$withdrawal->id}, TXN: {$withdrawal->txn}");

            } catch (\Exception $e) {
                Log::error('TON withdrawal processing failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to process withdrawal ID: {$withdrawal->id} - {$e->getMessage()}");
            }
        }

        if ($processed > 0) {
            $this->info("Processed {$processed} TON withdrawals");
        }

        return 0;
    }
}
