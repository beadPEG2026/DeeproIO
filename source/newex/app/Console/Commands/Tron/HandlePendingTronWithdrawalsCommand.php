<?php

namespace App\Console\Commands\Tron;

use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Tron\Services\TronService;
use Illuminate\Console\Command;

class HandlePendingTronWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:trx-withdrawals-pending';

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
        })->whereIn('network_id', [NETWORK_TRX, NETWORK_TRC])->get();

        foreach ($withdrawals as $withdrawal) {

            if($withdrawal->txn) {
                $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                $withdrawal->update();
            }

            $type = $withdrawal->network_id == NETWORK_TRX ? 'trx' : 'trc20';

            (new TronService())->handleWithdraw($withdrawal, $type);

        }
    }
}
