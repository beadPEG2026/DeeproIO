<?php

namespace App\Console\Commands\Ton;

use App\Models\Network\Network;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Services\PaymentGateways\Coin\Ton\Services\TonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Setting;

class MonitorTonDepositsCommand extends Command
{
    protected $signature = 'ton:monitor-ton-deposits';

    protected $description = 'Monitor TON deposits to the system wallet and credit users by memo';

    public $depositRepository;
    public $service;

    public function __construct()
    {
        parent::__construct();
        $this->depositRepository = new DepositRepository();
        $this->service = new TonService();
    }

    public function handle()
    {
        if (Network::where('id', NETWORK_TON)->where('deposit_status', false)->count()) {
            return false;
        }

        $currency = (new CurrencyRepository())->getCurrencyBySymbol('TON');
        if (!$currency || in_array(NETWORK_TON, $currency->disabled_deposit_networks)) return;

        $systemWallet = Setting::get('ton.wallet');
        $transactions = $this->service->getTransactions($systemWallet);

        foreach ($transactions as $transaction) {
            if(config('app.readonly')) { continue; }
            try {
                DB::transaction(function () use ($transaction) {
                    $this->service->storeTonDeposit($transaction);
                    return true;
                }, DB_REPEAT_AFTER_DEADLOCK);
            } catch (\Exception $e) {
                Log::error($e);
            }
        }
    }
}
