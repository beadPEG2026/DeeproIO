<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Currency\Currency;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\PaymentGateways\Fiat\Deriv\Services\DerivService;
use App\Services\PaymentGateways\Fiat\PerfectMoney\Services\PerfectMoney;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PerfectMoneyBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:perfect-money-wallet-balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        try {

            $currency = Currency::with('networks')->whereHas('networks', function($q){
                $q->where('network_id', NETWORK_PERFECT_MONEY);
            })->first();

            if($currency) {

                $service = new PerfectMoney();

                $balance = $service->getBalance();

                $currency->wallet_balance = $balance ?? 0;
                $currency->wallet_balance_updated_at = Carbon::now();
                $currency->update();

                Log::info('Perfect Money system wallet balances were updated');

            }

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update Perfect Money balances');
        }
    }
}
