<?php

namespace App\Console\Commands\Solana;

use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Bnb\Services\BnbService;
use App\Services\PaymentGateways\Coin\Solana\Services\SolanaService;
use Illuminate\Console\Command;

class HandlePendingSolWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'solana:sol-withdrawals-pending';

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
        })->whereIn('network_id', [NETWORK_SOL, NETWORK_SOL_SPL])->get();

        foreach ($withdrawals as $withdrawal) {

            if($withdrawal->txn) {
                $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                $withdrawal->update();
            }

            $type = $withdrawal->network_id == NETWORK_SOL ? 'sol' : 'solspl';

            (new SolanaService())->handleWithdraw($withdrawal, $type);

        }
    }
}
