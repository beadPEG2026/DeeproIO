<?php

namespace App\Jobs\Deposit\Solana;

use App\Helpers\PaymentGateways\Solana\SolanaNodeHelper;
use App\Models\Wallet\WalletAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class HandlePendingSolDepositJob implements ShouldQueue
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

        $response = Http::post(SolanaNodeHelper::route('wallet.transfer.main.to.wallet'), [
            'id' => $this->deposit['id'],
            'address' => $this->deposit['address'],
            'amount' => $this->deposit['amount'],
            'lamports' => $this->deposit['lamports'],
            'hash' => $this->deposit['hash'],
            'private_key' => $walletAddress->private_key,
            'to' => setting('solana.wallet'),
            'fee' => true,
        ]);

        if(!isset($response['message'])) {
            $this->fail($response->json());
        }
    }
}
