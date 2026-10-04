<?php

namespace App\Console\Commands\Bonus;

use App\Models\Deposit\Deposit;
use App\Services\Bonus\BonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessDepositBonusesCommand extends Command
{
    protected $signature = 'bonus:process-deposits';
    protected $description = 'Process deposit bonuses for confirmed deposits that have not received bonuses yet';

    public function handle(): int
    {
        if(config('app.deposit_bonus_enabled') !== true) {
            $this->info('Deposit bonuses are disabled in configuration.');
            return true;
        }

        $this->info('Processing deposit bonuses...');

        // Process only deposits with a deposit_id for idempotency
        $query = Deposit::query()
            ->where('status', DEPOSIT_CONFIRMED)
            ->whereNotNull('deposit_id')
            ->whereNull('internal_id')
            ->where('awarded', false)
            ->orderBy('id');

        $count = 0;
        $service = new BonusService();

        $query->chunkById(200, function ($deposits) use (&$count, $service) {
            foreach ($deposits as $deposit) {
                try {
                    // Compute credited amount as amount - system_fee (consistent for most gateways)
                    $credited = math_sub($deposit->amount, $deposit->system_fee);
                    if (math_compare($credited, 0) <= 0) {
                        continue;
                    }
                    $service->awardForDeposit((int) $deposit->user_id, (int) $deposit->currency_id, (string) $credited, (string) $deposit->deposit_id);
                    $count++;
                    $deposit->awarded = true;
                    $deposit->save();
                } catch (\Throwable $e) {
                    \Log::error('ProcessDepositBonusesCommand error: ' . $e->getMessage(), ['deposit_id' => $deposit->deposit_id]);
                }
            }
        });

        $this->info("Processed bonuses for {$count} deposits.");
        return Command::SUCCESS;
    }
}
