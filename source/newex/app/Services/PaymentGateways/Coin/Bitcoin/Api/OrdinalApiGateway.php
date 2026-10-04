<?php
namespace App\Services\PaymentGateways\Coin\Bitcoin\Api;

use App\Helpers\PaymentGateways\Bnb\BnbNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class OrdinalApiGateway implements CoinGatewayInterface
{
    protected $baseUrl = '';
    protected $apiKey = '';
    protected $client;

    public function __construct() {
        $this->baseUrl = config('bitcoind.default.ordinal_api');
        $this->apiKey = config('bitcoind.default.ordinal_key');
    }

    public function createOrder($address, $ticker, $amount) {

        $balance = $this->getBalance($address, $ticker);

        if(!$balance || !isset($balance['total']) || $balance['total'] < $amount) {
            return [
                'order_id' => null,
                'txn' => null
            ];
        }

        $res = Http::withToken($this->apiKey)->post($this->baseUrl . "/v2/inscribe/order/create/brc20-transfer", [
            "receiveAddress" => $address,
            "feeRate" => 1,
            "outputValue" => 546,
            "devAddress" => "",
            "devFee" => 0,
            "brc20Ticker" => $ticker,
            "brc20Amount" => (string)($amount + 0),
        ]);

        $data = $res->json();
        Log::info($data);
        Log::info('-------------------------------------');
        // Response is not successful
        if(!isset($data['code']) || $data['code'] != 0) {
            return [
                'order_id' => null,
                'txn' => null
            ];
        }

        $addressToPayFee = $data['data']['payAddress'];

        $amount = math_divide($data['data']['amount'], bcpow('10', '8', 0));

        $response = bitcoind()->sendToAddress($addressToPayFee, $amount);

        if ($response->hasError()) {
            return [
                'order_id' => null,
                'txn' => null
            ];
        }

        //return the tx id
        $txn = $response->get();

        // Until this time tx is being confirmed, after confirmation inscription will be active and transferable.
        return [
            'order_id' => $data['data']['orderId'],
            'txn' => $txn
        ];
    }

    public function getInscriptionByOrderId($id) {

        $res = Http::withToken($this->apiKey)->get($this->baseUrl . "/v2/inscribe/order/" . $id);

        $data = $res->json();

        // Response is not successful
        if(isset($data['data']['status']) && $data['data']['status'] == "minted" && $data['data']['files'][0]['status'] == "confirmed") {
            return $data['data']['files'][0]['inscriptionId'];
        }

        return false;
    }

    public function getBalance($address, $ticker) {

        $res = Http::withToken($this->apiKey)->get($this->baseUrl . "/v1/indexer/address/{$address}/brc20/{$ticker}/info");

        $data = $res->json();

        if(isset($data['data']['ticker'])) {
            return [
                'total' => $data['data']['availableBalance'],
                'transferableBalance' => $data['data']['transferableBalance'],
            ];
        }

        return false;
    }

    public function getInscription($symbol, $tx) {

        $res = Http::withToken($this->apiKey)->get($this->baseUrl . "/v1/indexer/brc20/{$symbol}/tx/{$tx}/history?type=send&start=0&limit=16");

        $data = $res->json();

        if(isset($data['data']['detail'][0]['ticker'])
            && $data['data']['detail'][0]['valid'] === true
            && $data['data']['detail'][0]['type'] === "inscribe-transfer")
        {

            $inscription = $data['data']['detail'][0];

            return [
                'ticker' => $inscription['ticker'],
                'amount' => $inscription['amount'],
                'to' => $inscription['to'],
            ];
        }

        return false;
    }
}
