<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Coinpayments\Api\CoinpaymentsGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CoinpaymentsBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:coinpayments-wallet-balance';

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

            $coinpaymentsPrivateKey = setting('coinpayments.private_key');

            if(!$coinpaymentsPrivateKey) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $balances = (new CoinpaymentsGateway())->getBalance();

            if(!$balances || !isset($balances->result)) return;

            $currencies->each(function ($currency) use ($balances) {

                if ($currency->alt_symbol !== null) {

                    if(isset($balances->result->{$currency->alt_symbol})) {

                        $amount = $balances->result->{$currency->alt_symbol}->balancef;

                        ((new ColdStorageService())->transferCheck(NETWORK_COINPAYMENTS, $amount, $currency->id));

                        $currency->wallet_balance = $amount;
                    } else {
                        $currency->wallet_balance = 0;
                    }

                    $currency->wallet_balance_updated_at = Carbon::now();
                    $currency->update();
                }
            });

            Log::info('Coinpayments system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update Coinpayments balances');
        }
    }
}
