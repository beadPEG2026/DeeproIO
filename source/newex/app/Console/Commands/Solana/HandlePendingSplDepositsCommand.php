<?php

namespace App\Console\Commands\Solana;

use App\Jobs\Deposit\Solana\HandlePendingSplDepositJob;
use App\Models\Wallet\WalletAddress;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingSplDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'solana:spl-transfer-pending';

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
        $deposits = Deposit::with('currency')->transferPending()->confirmed()->where('network_id', NETWORK_SOL_SPL)->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = 'queued';
            $deposit->update();

            $wallet = WalletAddress::where('address', $deposit->address)->first();

            dispatch_sync(new HandlePendingSplDepositJob([
                'id' => $deposit->id,
                'address' => $deposit->address,
                'token_account' => $wallet->token_account,
                'contract' => $deposit->currency->sol_contract,
                'hash' => $deposit->txn,
                'amount' => math_formatter($deposit->amount, 12),
                'lamports' => $deposit->full_amount,
            ]));

            sleep(5);
        }
    }
}
