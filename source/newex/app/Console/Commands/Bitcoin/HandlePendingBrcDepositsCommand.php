<?php

namespace App\Console\Commands\Bitcoin;

use App\Models\Deposit\Deposit;
use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HandlePendingBrcDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'btc:brc-deposits-inscribing';

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
        // Revert to queue recently failed records
        Deposit::where('wallet_transfer_status', DEPOSIT_INSCRIBING_FAILED)->where('status', DEPOSIT_CONFIRMED)->where('created_at', '<', Carbon::now()->subMinutes(5)->toDateTimeString())->update([
            'wallet_transfer_status' => DEPOSIT_PENDING
        ]);

        // Revert to queue recently stuck records
        Deposit::where('wallet_transfer_status', DEPOSIT_INSCRIBING_ORDERED)->where('created_at', '<', Carbon::now()->subMinutes(30)->toDateTimeString())->update([
            'wallet_transfer_status' => DEPOSIT_PENDING
        ]);

        $deposits = Deposit::where(function($query) {
            $query->where(function($query) {
                $query->where('wallet_transfer_status', DEPOSIT_PENDING);
            });
        })->whereIn('network_id', [NETWORK_BRC20])->with('currency')->get();

        foreach ($deposits as $deposit) {

            $deposit->wallet_transfer_status = DEPOSIT_INSCRIBING;
            $deposit->save();

            $api = new OrdinalApiGateway();

            $response = $api->createOrder($deposit->address, $deposit->currency->symbol, $deposit->amount);

            if($response['txn'] && $response['order_id']) {
                $deposit->wallet_transfer_status = DEPOSIT_INSCRIBING_ORDERED;
                $deposit->raw = ['order_id' => $response['order_id'], 'txn' => $response['txn']];
                $deposit->save();
            } else {
                $deposit->wallet_transfer_status = DEPOSIT_INSCRIBING_FAILED;
                $deposit->save();
            }

            sleep(3);
        }
    }
}
