<?php

namespace App\Console\Commands\Customtoken;

use App\Jobs\Deposit\Custom\HandlePendingCustomDepositJob;
use Illuminate\Console\Command;
use App\Models\Deposit\Deposit;

class HandlePendingTokenDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:token-transfer-pending';

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
        $deposits = Deposit::with('currency')->transferPending()->where('status', DEPOSIT_PENDING_TRANSFER)->where('network_id', NETWORK_CUSTOMTOKEN_TOKEN)->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = 'queued';
            $deposit->update();

            dispatch_sync(new HandlePendingCustomDepositJob([
                'id' => $deposit->id,
                'deposit_id' => $deposit->id,
                'address' => $deposit->address,
                'hash' => $deposit->txn,
                'amount' => math_formatter($deposit->amount, 12),
                'wei' => $deposit->full_amount,
                'contract' => $deposit->currency->custom_contract
            ]));

            sleep(5);
        }
    }
}
