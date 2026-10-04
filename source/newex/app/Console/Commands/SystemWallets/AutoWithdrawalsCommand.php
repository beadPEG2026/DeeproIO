<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'withdrawal:automate';

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

            if(!config('app.auto_withdrawals')) return;

            $withdrawals = Withdrawal::where('status', 'waiting_approval')->with('currency')->limit(5)->get();

            foreach ($withdrawals as $withdrawal) {

                $systemBalance = $withdrawal->currency->wallet_balance;

                if($withdrawal->network_id == NETWORK_BEP) {
                    $systemBalance = $withdrawal->currency->wallet_balance_bep;
                } elseif($withdrawal->network_id == NETWORK_ERC) {
                    $systemBalance = $withdrawal->currency->wallet_balance_erc;
                } elseif($withdrawal->network_id == NETWORK_TRC) {
                    $systemBalance = $withdrawal->currency->wallet_balance_trc;
                } elseif($withdrawal->network_id == NETWORK_MATIC20) {
                    $systemBalance = $withdrawal->currency->wallet_balance_matic;
                } elseif($withdrawal->network_id == NETWORK_SOL_SPL) {
                    $systemBalance = $withdrawal->currency->wallet_balance_sol;
                }

                if($systemBalance >= $withdrawal->amount) {
                    $result = (new WithdrawalRepository())->moderate($withdrawal, 'approve');
                }
            }

        } catch (\Exception $e) {
            Log::error($e);
        }
    }
}
