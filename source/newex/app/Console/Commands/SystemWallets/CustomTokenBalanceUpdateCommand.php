<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use App\Services\PaymentGateways\Coin\Customtoken\Api\CustomtokenGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CustomTokenBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:customtoken-token-wallet-balance';

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

            $ethereumPrivateKey = setting('customtoken.private_key');
            $ethereumWallet = setting('customtoken.wallet');

            if(!$ethereumPrivateKey || !$ethereumWallet) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $ethereumGateway = new CustomtokenGateway();

            $currencies->each(function ($currency) use ($ethereumGateway, $ethereumWallet) {

                if(isset($currency->networks) && in_array(NETWORK_CUSTOMTOKEN_TOKEN, $currency->networks->pluck('id')->toArray())) {

                    $response = $ethereumGateway->getTokenBalance($ethereumWallet, $currency->custom_contract);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['message'];

                        $networkErc = in_array(NETWORK_CUSTOMTOKEN_TOKEN, $currency->networks->pluck('id')->toArray());

                        ((new ColdStorageService())->transferCheck(NETWORK_CUSTOMTOKEN_TOKEN, $amount, $currency->id));

                        $currency->wallet_balance = $amount;
                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('Custom Network system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('Custom Network system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update Custom Network balances');
        }
    }
}
