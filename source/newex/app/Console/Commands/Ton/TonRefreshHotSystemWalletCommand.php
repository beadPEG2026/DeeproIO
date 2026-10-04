<?php

namespace App\Console\Commands\Ton;

use App\Models\Wallet\WalletAddress;
use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use Setting;
use Illuminate\Console\Command;

class TonRefreshHotSystemWalletCommand extends Command
{
    protected $signature = 'ton:refresh-system-wallet';

    protected $description = 'Run this command after each hot wallet changes to remove old TON memos';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $service = new TonService();
        $wallet = $service->generateWallet();

        Setting::set('ton.wallet', $wallet['address']);
        Setting::set('ton.private_key', $wallet['seed']);
        Setting::save();

        WalletAddress::where('network_id', NETWORK_TON)->delete();

        $this->info('TON system wallet rotated and user memos cleared.');
    }
}
