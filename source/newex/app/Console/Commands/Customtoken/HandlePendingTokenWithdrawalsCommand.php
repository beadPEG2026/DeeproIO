<?php

namespace App\Console\Commands\Customtoken;

use App\Jobs\Deposit\Eth\HandlePendingEthDepositJob;
use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Ethereum\Services\EthereumService;
use App\Services\PaymentGateways\Coin\Customtoken\Services\CustomtokenService;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingTokenWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:token-withdrawals-pending';

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
        $withdrawals = Withdrawal::where(function($query) {
            $query->where(function($query) {
                $query->where('status', WITHDRAWAL_WAITING_PROVIDER_APPROVAL)->whereNotNull('txn');
            });
        })->whereIn('network_id', [NETWORK_CUSTOMTOKEN_TOKEN])->get();

        foreach ($withdrawals as $withdrawal) {

            if($withdrawal->txn) {
                $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                $withdrawal->update();
            }

            (new CustomtokenService())->handleWithdrawToken($withdrawal);

        }
    }
}
