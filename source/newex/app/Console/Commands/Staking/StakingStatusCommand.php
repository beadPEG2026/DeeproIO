<?php

namespace App\Console\Commands\Staking;

use App\Models\Staking\StakingUser;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Staking\StakingWalletService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StakingStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staking:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Complete matured staking positions and return principal + rewards to users';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $walletRepository = new WalletRepository();
        $stakingWalletService = new StakingWalletService();
        
        $processed = 0;
        $errors = 0;

        // Find stakes that have reached their redemption date
        // Use <= to catch any stakes that might have been missed on previous runs
        StakingUser::active()->when(\Illuminate\Support\Facades\Schema::hasColumn('staking_users','meta'),fn($q)=>$q->whereNull('meta->funded_term'))
            ->where('redemption_date', '<=', Carbon::now()->format('Y-m-d 23:59:59'))
            ->chunk(100, function($stakes) use ($walletRepository, $stakingWalletService, &$processed, &$errors) {
                foreach ($stakes as $stake) {
                    try {
                        DB::beginTransaction();

                        // Lock the stake record
                        $stake = StakingUser::where('id', $stake->id)->lockForUpdate()->first();
                        
                        if (!$stake || $stake->status !== 'active') {
                            DB::rollBack();
                            continue;
                        }

                        $wallet = $walletRepository->getWalletByCurrency($stake->user_id, $stake->currency_id, true);

                        if ($wallet) {
                            // Return principal + accumulated rewards to the original real/virtual wallet.
                            $totalAmount = math_sum($stake->amount, $stake->reward ?? '0');
                            $returnField = $stakingWalletService->getReturnBalanceField($stake);
                            $stakingWalletService->increase($wallet, $returnField, $totalAmount);
                        } else {
                            Log::warning("Wallet not found for staking completion. User: {$stake->user_id}, Currency: {$stake->currency_id}");
                        }

                        $stake->status = 'completed';
                        $stake->save();

                        DB::commit();
                        $processed++;

                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("Error completing staking {$stake->id}: " . $e->getMessage());
                        $errors++;
                    }
                }
            });

        $this->info("Completed {$processed} stakes. Errors: {$errors}");

        return 0;
    }
}
