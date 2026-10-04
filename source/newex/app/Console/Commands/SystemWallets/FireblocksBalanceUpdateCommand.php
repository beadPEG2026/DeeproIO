<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Currency\Currency;
use App\Services\Fireblocks\FireblocksSDK;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FireblocksBalanceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:fireblocks-balance';

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

            $hotWalletVault = config('fireblocks.hot_wallet_vault');

            $response = (new FireblocksSDK())->get_vault_assets_balance($hotWalletVault);

            if(!isset($response[0]['id'])) return;

            $fireblockNetworks = config('fireblocks.networks');

            $fireblocksCurrencies = [];

            foreach ($fireblockNetworks as $networkId => $network) {

                foreach ($network as $symbol => $networkAsset) {
                    $fireblocksCurrencies[$networkAsset] = [$symbol, $networkId];
                }

            }

            foreach ($response as $asset) {
                $symbol = $asset['id'];

                $activeCurrency = $fireblocksCurrencies[$symbol];

                $currency = Currency::where('symbol', $activeCurrency[0])->first();

                if($activeCurrency[1] == NETWORK_BEP) {
                    $currency->wallet_balance_bep = $asset['available'];
                } elseif($activeCurrency[1] == NETWORK_ERC) {
                    $currency->wallet_balance_erc = $asset['available'];
                } elseif($activeCurrency[1] == NETWORK_TRC) {
                    $currency->wallet_balance_trc = $asset['available'];
                } elseif($activeCurrency[1] == NETWORK_MATIC20) {
                    $currency->wallet_balance_matic= $asset['available'];
                } else {
                    $currency->wallet_balance = $asset['available'];
                }

                $currency->update();
            }

            Log::info('Fireblocks wallet balances were updated');

        } catch (\Exception $e) {
            Log::error($e);
            Log::info('Could not update Fireblocks balances');
        }
    }
}
