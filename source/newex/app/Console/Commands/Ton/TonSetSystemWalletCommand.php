<?php

namespace App\Console\Commands\Ton;

use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use Setting;
use Illuminate\Console\Command;

class TonSetSystemWalletCommand extends Command
{
    protected $signature = 'ton:system-wallet-generate';

    protected $description = 'Run this command to set system wallet for TON';

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

        $this->info('TON system wallet updated.');
    }
}
