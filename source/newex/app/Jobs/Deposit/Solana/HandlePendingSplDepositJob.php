<?php

namespace App\Jobs\Deposit\Solana;

use App\Helpers\PaymentGateways\Solana\SolanaNodeHelper;
use App\Models\Wallet\WalletAddress;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class HandlePendingSplDepositJob implements ShouldQueue
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

        $response = Http::post(SolanaNodeHelper::route('wallet.transfer.main.to.wallet.spl'), [
            'id' => $this->deposit['id'],
            'address' => $this->deposit['address'],
            'token_account' => $this->deposit['token_account'],
            'amount' => $this->deposit['amount'],
            'lamports' => $this->deposit['lamports'],
            'mint' => $this->deposit['contract'],
            'hash' => $this->deposit['hash'],
            'private_key' => $walletAddress->private_key,
            'system_wallet' => setting('solana.wallet'),
            'system_wallet_pk' => (new SolanaGateway())->jsonToHex(setting('solana.private_key')),
            'fee' => true,
        ]);

        if(!isset($response['message'])) {
            $this->fail($response->json());
        }
    }
}
