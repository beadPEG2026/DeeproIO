<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Customtoken\Api\CustomtokenGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncCustomtoken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:sync-system';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {

            $customtokenWallet = setting('customtoken.wallet');

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $customtokenGateway = new CustomtokenGateway();

            $currencies->each(function ($currency) use ($customtokenGateway, $customtokenWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_CUSTOMTOKEN_NETWORK, $currency->networks->pluck('id')->toArray()))) {

                    $response = $customtokenGateway->getBalance($customtokenWallet);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['balance'];

                        ((new ColdStorageService())->transferCheck(NETWORK_CUSTOMTOKEN_NETWORK, $amount, $currency->id));

                        $currency->wallet_balance = $amount;

                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('Customtoken system wallet balances were not updated for ' . $currency->symbol);
                        Log::error('Query failed for getBalance Customtoken');
                    }

                    sleep(3);
                }
            });

            Log::info('Customtoken system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update Customtoken balances');
        }
    }
}
