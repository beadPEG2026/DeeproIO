<?php

namespace App\Console\Commands\SystemWallets;

use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Tron\Api\TronGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TrxTrcBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:trx-wallet-balance';

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

            $tronPrivateKey = setting('tron.private_key');
            $tronWallet = setting('tron.wallet');

            if(!$tronPrivateKey || !$tronWallet) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $tronGateway = new TronGateway();

            $currencies->each(function ($currency) use ($tronGateway, $tronWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_TRX, $currency->networks->pluck('id')->toArray()) || in_array(NETWORK_TRC, $currency->networks->pluck('id')->toArray()))) {

                    $response = $tronGateway->getBalance($tronWallet, $currency->trc_contract);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['message'];

                        $networkTrc = in_array(NETWORK_TRC, $currency->networks->pluck('id')->toArray());

                        ((new ColdStorageService())->transferCheck($networkTrc ? NETWORK_TRC : NETWORK_TRX, $amount, $currency->id));

                        if($networkTrc)
                            $currency->wallet_balance_trc = $amount;
                        else
                            $currency->wallet_balance = $amount;

                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('TRX/TRC system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('TRX/TRC system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update TRX/TRC balances');
        }
    }
}
