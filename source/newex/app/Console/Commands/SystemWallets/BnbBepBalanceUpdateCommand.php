<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Bnb\Api\BnbGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BnbBepBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:bnb-wallet-balance';

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

            $bnbPrivateKey = setting('bnb.private_key');
            $bnbWallet = setting('bnb.wallet');

            if(!$bnbPrivateKey || !$bnbWallet) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $bnbGateway = new BnbGateway();

            $currencies->each(function ($currency) use ($bnbGateway, $bnbWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_BNB, $currency->networks->pluck('id')->toArray()) || in_array(NETWORK_BEP, $currency->networks->pluck('id')->toArray()))) {

                    $response = $bnbGateway->getBalance($bnbWallet, $currency->bep_contract);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['message'];

                        $networkBep = in_array(NETWORK_BEP, $currency->networks->pluck('id')->toArray());

                        ((new ColdStorageService())->transferCheck($networkBep ? NETWORK_BEP : NETWORK_BNB, $amount, $currency->id));

                        if($networkBep)
                            $currency->wallet_balance_bep = $amount;
                        else
                            $currency->wallet_balance = $amount;

                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('BNB/BEP system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('BNB/BEP system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update BNB/BEP balances');
        }
    }
}
