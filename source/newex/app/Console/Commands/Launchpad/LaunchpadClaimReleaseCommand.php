<?php

namespace App\Console\Commands\Launchpad;

use App\Models\Launchpad\Launchpad;
use App\Models\Launchpad\LaunchpadTransaction;
use App\Models\Wallet\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LaunchpadClaimReleaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'launchpad:claim-release {launchpad}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release tokens to users who participated in a launchpad';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $launchpad = Launchpad::where('id', $this->argument('launchpad'))->first();

        if(!$launchpad) {
            $this->error('Launchpad was not found');
            return 1;
        }

        $this->info("Processing token release for launchpad: {$launchpad->name}");

        $walletService = new WalletService();
        $processed = 0;
        $errors = 0;
        $skipped = 0;

        // Process in chunks to handle large numbers of transactions
        LaunchpadTransaction::where('launchpad_id', $launchpad->id)
            ->where('is_credited', false)
            ->chunk(100, function($transactions) use ($launchpad, $walletService, &$processed, &$errors, &$skipped) {
                
                foreach ($transactions as $transaction) {
                    try {
                        DB::beginTransaction();

                        // Lock the transaction to prevent double crediting
                        $transaction = LaunchpadTransaction::where('id', $transaction->id)
                            ->where('is_credited', false)
                            ->lockForUpdate()
                            ->first();

                        if (!$transaction) {
                            // Already processed by another process
                            DB::rollBack();
                            $skipped++;
                            continue;
                        }

                        $wallet = Wallet::where('currency_id', $launchpad->currency_id)
                            ->where('user_id', $transaction->user_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$wallet) {
                            Log::warning("Wallet not found for launchpad claim. User: {$transaction->user_id}, Currency: {$launchpad->currency_id}");
                            DB::rollBack();
                            $errors++;
                            continue;
                        }

                        // Calculate tokens to credit: contribution * rate
                        // Rate represents tokens per unit of payment currency
                        $tokenAmount = math_multiply($transaction->amount, $launchpad->rate);

                        // Credit tokens to user's wallet
                        $walletService->increase($wallet, $tokenAmount, 'wallet');

                        // Mark as credited
                        $transaction->is_credited = true;
                        $transaction->save();

                        DB::commit();
                        $processed++;

                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("Error releasing tokens for transaction {$transaction->id}: " . $e->getMessage());
                        $errors++;
                    }
                }
            });

        $this->info("Processed: {$processed}, Errors: {$errors}, Skipped: {$skipped}");

        return $errors > 0 ? 1 : 0;
    }
}
