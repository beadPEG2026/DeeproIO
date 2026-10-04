<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EthErcBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:ethereum-wallet-balance';

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

            $ethereumPrivateKey = setting('ethereum.private_key');
            $ethereumWallet = setting('ethereum.wallet');

            if(!$ethereumPrivateKey || !$ethereumWallet) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $ethereumGateway = new EthereumGateway();

            $currencies->each(function ($currency) use ($ethereumGateway, $ethereumWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_ETH, $currency->networks->pluck('id')->toArray()) || in_array(NETWORK_ERC, $currency->networks->pluck('id')->toArray()))) {

                    $response = $ethereumGateway->getBalance($ethereumWallet, $currency->contract);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['message'];

                        $networkErc = in_array(NETWORK_ERC, $currency->networks->pluck('id')->toArray());

                        ((new ColdStorageService())->transferCheck($networkErc ? NETWORK_ERC : NETWORK_ETH, $amount, $currency->id));

                        if($networkErc)
                            $currency->wallet_balance_erc = $amount;
                        else
                            $currency->wallet_balance = $amount;

                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('ETH/ERC system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('ETH/ERC system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update ETH/ERC balances');
        }
    }
}
