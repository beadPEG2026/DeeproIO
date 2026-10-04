<?php

namespace App\Console\Commands\SystemWallets;

use App\Models\Withdrawal\FiatWithdrawal;
use App\Repositories\Withdrawal\FiatWithdrawalRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PerfectMoneyAutoWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallets:perfect-money-wallet-withdraw-automate';

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

            if(!config('perfectmoney.auto_withdrawals')) return;

            $withdrawals = FiatWithdrawal::where('status', 'pending')->where('type', NETWORK_PERFECT_MONEY_SLUG)->with('currency')->limit(5)->get();

            foreach ($withdrawals as $withdrawal) {

                if($withdrawal->currency->wallet_balance >= ($withdrawal->amount - $withdrawal->fee)) {
                    (new FiatWithdrawalRepository())->moderate($withdrawal, 'approve');
                }
            }

        } catch (\Exception $e) {
            Log::error($e);
        }
    }
}
