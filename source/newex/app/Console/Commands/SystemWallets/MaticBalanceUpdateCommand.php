<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use App\Services\PaymentGateways\Coin\Polygon\Api\PolygonGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MaticBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:matic-wallet-balance';

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

            $polygonPrivateKey = setting('polygon.private_key');
            $polygonWallet = setting('polygon.wallet');

            if(!$polygonPrivateKey || !$polygonWallet) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $gateway = new PolygonGateway();

            $currencies->each(function ($currency) use ($gateway, $polygonWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_MATIC, $currency->networks->pluck('id')->toArray()) || in_array(NETWORK_MATIC20, $currency->networks->pluck('id')->toArray()))) {

                    $response = $gateway->getBalance($polygonWallet, $currency->matic_contract);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['message'];

                        $networkMatic20 = in_array(NETWORK_MATIC20, $currency->networks->pluck('id')->toArray());

                        ((new ColdStorageService())->transferCheck($networkMatic20 ? NETWORK_MATIC20 : NETWORK_MATIC, $amount, $currency->id));

                        if($networkMatic20)
                            $currency->wallet_balance_matic = $amount;
                        else
                            $currency->wallet_balance = $amount;

                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('MATIC system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('MATIC system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update MATIC balances');
        }
    }
}
