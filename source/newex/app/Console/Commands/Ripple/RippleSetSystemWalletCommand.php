<?php

namespace App\Console\Commands\Ripple;

use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use Setting;
use Illuminate\Console\Command;

class RippleSetSystemWalletCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ripple:system-wallet-generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run this command to set system wallet for XRP';

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
        $service = new RippleService();
        $wallet = $service->generateWallet();

        Setting::set('ripple.wallet', $wallet['address']);
        Setting::set('ripple.private_key', $wallet['seed']);
        Setting::save();
    }
}
