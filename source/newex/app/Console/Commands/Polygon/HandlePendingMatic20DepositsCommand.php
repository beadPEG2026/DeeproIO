<?php

namespace App\Console\Commands\Polygon;

use App\Jobs\Deposit\Eth\HandlePendingErcDepositJob;
use App\Jobs\Deposit\Polygon\HandlePendingMatic20DepositJob;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingMatic20DepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matic:matic-20-transfer-pending';

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
        $deposits = Deposit::with('currency')->transferPending()->confirmed()->where('network_id', NETWORK_MATIC20)->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = 'queued';
            $deposit->update();

            dispatch_sync(new HandlePendingMatic20DepositJob([
                'id' => $deposit->id,
                'deposit_id' => $deposit->id,
                'address' => $deposit->address,
                'hash' => $deposit->txn,
                'amount' => math_formatter($deposit->amount, 12),
                'wei' => $deposit->full_amount,
                'contract' => $deposit->currency->matic_contract
            ]));

            sleep(5);
        }
    }
}
