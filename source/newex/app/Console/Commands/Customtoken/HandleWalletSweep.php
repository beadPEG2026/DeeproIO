<?php

namespace App\Console\Commands\Customtoken;

use App\Models\Deposit\Deposit;
use App\Models\Wallet\WalletAddress;
use App\Services\PaymentGateways\Coin\Customtoken\Api\CustomtokenGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Setting;

class HandleWalletSweep extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:sweep';

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

        $systemAddress = Setting::get('customtoken.wallet');

        if(!$systemAddress) {
            return;
        }

        $deposits = Deposit::where(function($query) {
            $query->where(function($query) {
                $query->where('wallet_transfer_status', DEPOSIT_PENDING);
                $query->where('status', DEPOSIT_PENDING_TRANSFER)->whereNotNull('txn');
            });
        })->whereIn('network_id', [NETWORK_CUSTOMTOKEN_NETWORK])->get();

        foreach($deposits as $deposit) {
            if($deposit->txn) {

                $deposit->wallet_transfer_status = 'queued';
                $deposit->update();

                // get transaction status

                $user_id = $deposit->user_id;

                $wallet = WalletAddress::where('user_id', $user_id)->where('network_id', NETWORK_CUSTOMTOKEN_NETWORK)->first();

                if(!$wallet) {
                    continue;
                }

                $balance = (new CustomtokenGateway())->getBalance($wallet->address);
                $transferable = (string)($balance['balance'] - 0.002);


                if($balance && isset($balance['balance']) && $balance['balance'] >= 1) {

                    $transfer = (new CustomtokenGateway())->transfer($wallet->address, $systemAddress, ($transferable), $wallet->private_key);

                    Log::info('Sweeping wallet: ' . $wallet->address . ' to ' . $systemAddress . ' amount: ' . ($transferable));
                    Log::info('Transfer response: ' . json_encode($transfer));

                    if(isset($transfer['txn'])) {
                        $deposit->wallet_transfer_status = 'processed';
                        $deposit->update();
                    }

                    sleep(3);
                }
            }
        }
    }
}
