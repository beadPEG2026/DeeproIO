<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Network\Network;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\ColdStorage\ColdStorageService;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SolSplBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:solana-wallet-balance';

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

            $solanaPrivateKey = setting('solana.private_key');
            $solanaWallet = setting('solana.wallet');

            if(!$solanaPrivateKey || !$solanaWallet) return;

            $isActive = Network::where('id', NETWORK_SOL)->where('status', true)->first();

            if(!$isActive) return;

            $isActive = Network::where('id', NETWORK_SOL_SPL)->where('status', true)->first();

            if(!$isActive) return;

            $currencyRepository = new CurrencyRepository();

            $filter['type'] = 'coin';

            $currencies = $currencyRepository->getReport($filter, false);
            $solanaGateway = new SolanaGateway();

            $currencies->each(function ($currency) use ($solanaGateway, $solanaWallet) {

                if(isset($currency->networks) && (in_array(NETWORK_SOL, $currency->networks->pluck('id')->toArray()) || in_array(NETWORK_SOL_SPL, $currency->networks->pluck('id')->toArray()))) {

                    $response = $solanaGateway->getBalance($solanaWallet, $currency->sol_contract);

                    if(isset($response['status']) && $response['status'] == "ok") {

                        $amount = $response['message'];

                        $networkSPL = in_array(NETWORK_SOL_SPL, $currency->networks->pluck('id')->toArray());

                        ((new ColdStorageService())->transferCheck($networkSPL ? NETWORK_SOL_SPL : NETWORK_SOL, $amount, $currency->id));

                        if($networkSPL)
                            $currency->wallet_balance_sol = $amount;
                        else
                            $currency->wallet_balance = $amount;

                        $currency->wallet_balance_updated_at = Carbon::now();
                        $currency->update();
                    } else {
                        Log::info('SOL/SPL system wallet balances were not updated for ' . $currency->symbol);
                    }

                    sleep(3);
                }
            });

            Log::info('SOL/SPL system wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update SOL/SPL balances');
        }
    }
}
