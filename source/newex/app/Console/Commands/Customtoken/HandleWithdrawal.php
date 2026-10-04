<?php

namespace App\Console\Commands\Customtoken;

use App\Models\Withdrawal\Withdrawal;
use App\Services\PaymentGateways\Coin\Customtoken\Api\CustomtokenGateway;
use App\Services\PaymentGateways\Coin\Customtoken\Services\CustomtokenService;
use Illuminate\Console\Command;

class HandleWithdrawal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:handle-withdrawal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $withdrawals = Withdrawal::where(function($query) {
            $query->where(function($query) {
                $query->where('status', WITHDRAWAL_WAITING_PROVIDER_APPROVAL)->whereNotNull('txn');
            });
        })->whereIn('network_id', [NETWORK_CUSTOMTOKEN_NETWORK])->get();

        foreach ($withdrawals as $withdrawal) {

            if($withdrawal->txn) {

                // get transaction status
                $transaction = (new CustomtokenGateway())->getTransaction($withdrawal->txn);


                if(!$transaction || (isset($transaction['statusCode']) && $transaction['statusCode'] > 400)) {
                    continue;
                }

                if(isset($transaction['confirmations']) && $transaction['confirmations'] >= 6) {
                    $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                    $withdrawal->update();

                    (new CustomtokenService())->handleWithdraw($withdrawal);
                }
            }

            sleep(1);


        }
    }
}
