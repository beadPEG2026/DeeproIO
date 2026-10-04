<?php

namespace App\Console\Commands\Lending;

use App\Models\Lending\LendingTransactions;
use App\Models\Lending\LendingUser;
use App\Repositories\Transaction\ReferralTransactionRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LendingInterestTakerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lending:interest-taker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lending Interest Taker Function';

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
        LendingUser::active()->with('lending')->chunk(100, function($lendings) {

            foreach ($lendings as $lending) {

                $type = $lending->type;

                if($type == "flexible") {
                    $rate = $lending->lending->annual_rate_flexible;
                } elseif($type == "weekly") {
                    $rate = $lending->lending->annual_rate_weekly;
                } elseif($type == "monthly") {
                    $rate = $lending->lending->annual_rate_monthly;
                }

                $hourlyRate = math_divide(math_divide($rate, 365), 60);
                $rateAmount = math_percentage($lending->collateral_amount, $hourlyRate);

                $lending->collateral_amount = $lending->collateral_amount - $rateAmount;
                $lending->update();

                $lendingTx = new LendingTransactions();
                $lendingTx->lending_id = $lending->id;
                $lendingTx->user_id = $lending->user_id;
                $lendingTx->currency_id = $lending->currency_id;
                $lendingTx->amount = $rateAmount;
                $lendingTx->collateral_id = $lending->collateral_id;
                $lendingTx->save();
            }
        });
    }
}
