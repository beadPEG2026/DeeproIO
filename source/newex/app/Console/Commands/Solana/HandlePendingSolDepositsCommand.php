<?php

namespace App\Console\Commands\Solana;

use App\Jobs\Deposit\Solana\HandlePendingSolDepositJob;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingSolDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'solana:sol-transfer-pending';

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
        $deposits = Deposit::transferPending()->confirmed()->where('network_id', NETWORK_SOL)->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = 'queued';
            $deposit->update();

            dispatch_sync(new HandlePendingSolDepositJob([
                'id' => $deposit->id,
                'address' => $deposit->address,
                'hash' => $deposit->txn,
                'amount' => math_formatter($deposit->amount, 12),
                'lamports' => $deposit->full_amount,
            ]));

            sleep(5);
        }
    }
}
