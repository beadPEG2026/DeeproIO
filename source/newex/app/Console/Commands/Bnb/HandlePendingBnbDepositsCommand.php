<?php

namespace App\Console\Commands\Bnb;

use App\Jobs\Deposit\Bnb\HandlePendingBnbDepositJob;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingBnbDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bnb:bnb-transfer-pending';

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
        $deposits = Deposit::confirmed()->transferPending()->where('network_id', NETWORK_BNB)->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = 'queued';
            $deposit->update();

            dispatch_sync(new HandlePendingBnbDepositJob([
                'id' => $deposit->id,
                'deposit_id' => $deposit->deposit_id,
                'contract' => null,
                'address' => $deposit->address,
                'hash' => $deposit->txn,
                'amount' => math_formatter($deposit->amount, 8),
                'wei' => $deposit->full_amount,
            ]));

            sleep(5);
        }
    }
}
