<?php

namespace App\Console\Commands\Lending;

use App\Mail\Deposits\DepositReceived;
use App\Mail\Lending\LendingLiquidated;
use App\Mail\Lending\LendingMarginCall;
use App\Models\Lending\LendingTransactions;
use App\Models\Lending\LendingUser;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Transaction\ReferralTransactionRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class LendingLiquidationWatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lending:liquidation-watcher';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lending Liquidation Function';

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
        $currencyRepository = new CurrencyRepository();

        LendingUser::active()->with(['lending', 'currency', 'collateral', 'loan'])->chunk(100, function($lendings) use ($currencyRepository) {

            foreach ($lendings as $lending) {

                $type = $lending->type;
                $liquidationLtv = 0;
                $marginCall = 0;

                if($type == "flexible") {
                    $liquidationLtv = $lending->loan->flex_liquidation_ltv;
                    $marginCall = $lending->loan->flex_margin_call;
                } elseif($type == "weekly") {
                    $liquidationLtv = $lending->loan->weekly_liquidation_ltv;
                    $marginCall = $lending->loan->weekly_margin_call;
                } elseif($type == "monthly") {
                    $liquidationLtv = $lending->loan->monthly_liquidation_ltv;
                    $marginCall = $lending->loan->monthly_margin_call;
                }

                $sourceCurrencyPrice = math_multiply($currencyRepository->currencyPriceInUsd($lending->currency), $lending->remaining_amount);
                $collateralCurrencyPrice = math_multiply($currencyRepository->currencyPriceInUsd($lending->collateral), $lending->collateral_amount);

                if($collateralCurrencyPrice > 0) {
                    $ltv = math_multiply(math_divide($sourceCurrencyPrice, $collateralCurrencyPrice), 100);

                    if($liquidationLtv && $liquidationLtv > 0 && $ltv > $liquidationLtv) {

                        $lending->status = 'liquidated';
                        $lending->update();

                        Mail::to($lending->user)->queue(new LendingLiquidated($lending->user, $lending->remaining_amount, $lending->currency->symbol));
                    }

                    if($marginCall && $marginCall > 0 && $ltv > $marginCall && !$lending->is_notified) {

                        $lending->is_notified = true;
                        $lending->update();

                        Mail::to($lending->user)->queue(new LendingMarginCall($lending->user, $marginCall, $lending->remaining_amount, $lending->currency->symbol));

                    }
                }
            }
        });
    }
}
