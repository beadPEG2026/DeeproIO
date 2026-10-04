<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\BitcoinGateway;
use App\Services\PaymentGateways\Coin\Tron\Api\TronGateway;
use Carbon\Carbon;
use Denpa\Bitcoin\Facades\Bitcoind;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BitcoinBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:btc-wallet-balance';

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
            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);

            $currencies->each(function ($currency) {

                if(isset($currency->networks) && (in_array(NETWORK_BTC, $currency->networks->pluck('id')->toArray()) )) {

                    $managed=app(\App\Services\Wallet\BitcoinWalletManager::class)->active();
                    $amount=$managed?app(\App\Services\Custody\CustodyBridge::class)->call('bitcoin','balance',['sender'=>$managed->address])['balance']:number_format((float)BitcoinGateway::client()->getbalance()->result(),8,'.','');

                    ((new ColdStorageService())->transferCheck(NETWORK_BTC, $amount, $currency->id));

                    $currency->wallet_balance = $amount;
                    $currency->wallet_balance_updated_at = Carbon::now();
                    $currency->update();
                }
            });

            Log::info('BTC system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update BTC balances');
        }
    }
}
