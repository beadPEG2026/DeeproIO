<?php

namespace App\Jobs\Deposit\Eth;

use App\Helpers\PaymentGateways\Ethereum\EthereumNodeHelper;
use App\Models\Wallet\WalletAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HandlePendingErcDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deposit;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($deposit)
    {
        $this->deposit = $deposit;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        $walletAddress = WalletAddress::where('address', 'ilike', '%' . $this->deposit['address'] . '%')->first();

        $response = Http::post(EthereumNodeHelper::route('wallet.transfer.main.to.wallet.erc'), [
            'id' => $this->deposit['id'],
            'deposit_id' => $this->deposit['deposit_id'],
            'address' => $this->deposit['address'],
            'address_private_key' => $walletAddress->private_key,
            'amount' => $this->deposit['amount'],
            'wei' => $this->deposit['wei'],
            'wallet' => setting('ethereum.wallet'),
            'private_key' => setting('ethereum.private_key'),
            'fee' => true,
            'contract' => $this->deposit['contract'],
        ]);

        if($response->successful()) {

            Log::info('Handle Pending Erc Wallet Transfer Job processed');

        } else {
            $this->fail($response->body());
            Log::error($response->body());
            Log::info('Something went wrong with ' . $this->deposit['id']);
        }

    }
}
