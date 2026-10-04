<?php

namespace App\Console\Commands\Polygon;

use App\Jobs\Deposit\Eth\HandlePendingEthDepositJob;
use App\Jobs\Deposit\Polygon\HandlePendingMaticDepositJob;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingMaticDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matic:matic-transfer-pending';

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
        $deposits = Deposit::transferPending()->confirmed()->where('network_id', NETWORK_MATIC)->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = 'queued';
            $deposit->update();

            dispatch_sync(new HandlePendingMaticDepositJob([
                'id' => $deposit->id,
                'address' => $deposit->address,
                'hash' => $deposit->txn,
                'amount' => math_formatter($deposit->amount, 6),
                'wei' => $deposit->full_amount,
            ]));

            sleep(5);
        }
    }
}
