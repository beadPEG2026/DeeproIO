<?php

namespace App\Console\Commands\Bitcoin;

use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HandlePendingBrcWithdrawalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'btc:brc-withdrawals-inscribing';

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
        Withdrawal::where('extra_status', WITHDRAWAL_INSCRIBING_FAILED)->where('status', WITHDRAWAL_WAITING_APPROVAL)->where('created_at', '<', Carbon::now()->subMinutes(5)->toDateTimeString())->update([
            'extra_status' => WITHDRAWAL_INSCRIBING
        ]);

        $withdrawals = Withdrawal::where(function($query) {
            $query->where(function($query) {
                $query->where('extra_status', WITHDRAWAL_INSCRIBING);
            });
        })->whereIn('network_id', [NETWORK_BRC20])->with('currency')->get();

        foreach ($withdrawals as $withdrawal) {

            $withdrawal->extra_status = WITHDRAWAL_INSCRIBING_LOCKED;
            $withdrawal->save();

            $api = new OrdinalApiGateway();

            $amountAfterFee = math_sub($withdrawal->amount, $withdrawal->fee);

            $response = $api->createOrder(setting('bitcoin.wallet'), $withdrawal->currency->symbol, $amountAfterFee);

            if($response['txn'] && $response['order_id']) {
                $withdrawal->extra_status = WITHDRAWAL_INSCRIBING_ORDERED;
                $withdrawal->raw = ['order_id' => $response['order_id'], 'txn' => $response['txn']];
                $withdrawal->save();
            } else {
                $withdrawal->extra_status = WITHDRAWAL_INSCRIBING_FAILED;
                $withdrawal->save();
            }
        }
    }
}
