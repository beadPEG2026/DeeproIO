<?php

namespace App\Jobs\Deposit\Polygon;

use App\Helpers\PaymentGateways\Polygon\PolygonNodeHelper;
use App\Models\Wallet\WalletAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class HandlePendingMaticDepositJob implements ShouldQueue
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

        $response = Http::post(PolygonNodeHelper::route('wallet.transfer.main.to.wallet'), [
            'id' => $this->deposit['id'],
            'address' => $this->deposit['address'],
            'amount' => $this->deposit['amount'],
            'wei' => $this->deposit['wei'],
            'hash' => $this->deposit['hash'],
            'private_key' => $walletAddress->private_key,
            'to' => setting('polygon.wallet'),
            'fee' => true,
        ]);

        if(!isset($response['message'])) {
            $this->fail($response->json());
        }
    }
}
