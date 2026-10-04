<?php

namespace App\Console\Commands\Ethereum;

use App\Jobs\Deposit\Eth\HandlePendingEthDepositJob;
use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Ethereum\Services\EthereumService;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingEthereumWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ethereum:eth-withdrawals-pending';

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
        })->whereIn('network_id', [NETWORK_ETH, NETWORK_ERC])->get();

        foreach ($withdrawals as $withdrawal) {
            try {
                app(\App\Repositories\Withdrawal\WithdrawalRepository::class)->completeVerified($withdrawal->id, (string) $withdrawal->txn);
            } catch (\Illuminate\Validation\ValidationException $e) {
                \Illuminate\Support\Facades\Cache::put('withdrawal.review.' . $withdrawal->id, ['reason' => $e->errors(), 'at' => now()->toIso8601String()], 3600);
            }
        }
    }
}
