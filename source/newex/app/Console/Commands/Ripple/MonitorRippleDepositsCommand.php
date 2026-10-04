<?php

namespace App\Console\Commands\Ripple;

use App\Events\DepositUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Deposit\Deposit;
use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\PaymentGateways\Coin\Ripple\Services\RippleService;
use App\Services\PaymentGateways\Coin\Solana\Services\SolanaService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class MonitorRippleDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ripple:monitor-xrp-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public $depositRepository;

    public $service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->depositRepository = new DepositRepository();
        $this->service = new RippleService();

    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if(Network::where('id', NETWORK_RIPPLE)->where('deposit_status', false)->count()) {
            return false;
        }

        $currency = (new CurrencyRepository())->getCurrencyBySymbol('SOL');

        if (!$currency || in_array(NETWORK_RIPPLE, $currency->disabled_deposit_networks)) return;

        $systemWallet = Setting::get('ripple.wallet');

        $transactions = $this->service->getTransactions($systemWallet);

        foreach ($transactions as $transaction) {

            if(config('app.readonly')) {
                continue;
            }

            try {

                DB::transaction(function () use ($transaction) {

                    $this->service->storeXrpDeposit($transaction);

                    return true;

                }, DB_REPEAT_AFTER_DEADLOCK);

            } catch (\Exception $e) {
                Log::error($e);
            }
        }
    }
}
