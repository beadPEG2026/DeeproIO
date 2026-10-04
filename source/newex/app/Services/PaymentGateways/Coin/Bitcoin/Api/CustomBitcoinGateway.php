<?php
namespace App\Services\PaymentGateways\Coin\Bitcoin\Api;

use App\Helpers\PaymentGateways\Bnb\BnbNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class CustomBitcoinGateway implements CoinGatewayInterface
{
    public $url;
    public $token;
    public $walletname;
    public $walletPass;

    public function __construct()
    {
        $this->url = config('bitcoind.custom.url');
        $this->token = config('bitcoind.custom.token');
        $this->walletname = config('bitcoind.custom.walletname');
        $this->walletPass = config('bitcoind.custom.walletpass');
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createBitcoinAddress() {

        $params = [
            'method' => "getnewaddress",
            "params" => [],
            'wallet' => $this->walletname
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->url . '/api/wallet/request/node?token=' . $this->token, $params);

        $response = $response->json();

        return $response['data'] ?? null;
    }

    /**
     * Used to generate data to withdraw
     * @param $address
     * @param $amount
     * @return array
     */
    public function withdraw($type, $withdrawal, $address, $amount)
    {
        $params = [
            'method' => "sendtoaddress",
            "params" => [$address, $amount],
            'wallet' => $this->walletname
        ];

        $unlockParam = [
            "method" => "walletpassphrase",
            "params" => [$this->walletpass, 15],
            "wallet" => "$this->walletname"
        ];

        Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->url . '/api/wallet/request/node?token=' . $this->token, $unlockParam);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->url . '/api/wallet/request/node?token=' . $this->token, $params);

        $response = $response->json();

        $message = '';
        $source = '';

        if ($response['status'] === true) {
            $status = STATUS_VALIDATION_ERROR;
        } else {
            $status = STATUS_OK;
            $message = generate_string();
            $source = $message;
        }

        //return the tx id
        $txn = $response['data']['txid'];

        if($txn) {
            $withdrawal->txn = $txn;
            $withdrawal->update();
        }

        return [
            'status' => $status,
            'source' => $source,
            'message' => $message,
        ];
    }
}
