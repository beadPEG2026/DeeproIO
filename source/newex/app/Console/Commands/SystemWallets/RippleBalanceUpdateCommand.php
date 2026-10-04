<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RippleBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:ripple-wallet-balance';

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

            $ripplePrivateKey = setting('ripple.private_key');
            $rippleWallet = setting('ripple.wallet');

            if(!$ripplePrivateKey || !$rippleWallet) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $gateway = new RippleService();

            $currencies->each(function ($currency) use ($gateway, $rippleWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_RIPPLE, $currency->networks->pluck('id')->toArray()))) {

                    $response = $gateway->getBalance($rippleWallet);

                    if(isset($response['balance'])) {

                        $amount = $response['balance'];

                        ((new ColdStorageService())->transferCheck(NETWORK_RIPPLE, $amount, $currency->id));

                        $currency->wallet_balance = $amount;
                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('Ripple system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('Ripple system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update Ripple balances');
        }
    }
}
