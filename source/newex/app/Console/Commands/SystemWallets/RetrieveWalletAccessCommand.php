<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Wallet\WalletAddress;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use Illuminate\Console\Command;

class RetrieveWalletAccessCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'wallets:retrieve-wallet-access {address} {json?}';

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
     */
    public function handle()
    {
        $address = $this->argument('address', null);
        $json = $this->argument('json', null);

        if(!$address) {
            $this->info('Wallet Address is not provided');
            return;
        }

        $wallet = WalletAddress::where('address', $address)->first();

        if(!$wallet) {
            $this->info('Wallet Address not found');
            return;
        }

        if($json) {
            $this->info((new SolanaGateway())->getArrayedPrivateKey($wallet->private_key));
            return;
        }

        $this->info($wallet->private_key);
    }
}
