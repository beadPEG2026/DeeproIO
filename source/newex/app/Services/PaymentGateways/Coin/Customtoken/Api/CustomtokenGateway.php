<?php

namespace App\Services\PaymentGateways\Coin\Customtoken\Api;

use App\Helpers\PaymentGateways\Ethereum\EthereumNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class CustomtokenGateway implements CoinGatewayInterface
{

    public $nodeUrl;
    public $nodeTokenUrl;

    public function __construct() {
        $this->nodeUrl = config('app.customtoken_bridge');
        $this->nodeTokenUrl = config('app.customtoken_token_bridge');
    }

    public function generateAddress()
    {
        $response = Http::post($this->nodeUrl .'/generate');
        return $response->json();
    }

    public function getReceivedTransactions($address)
    {
        $response = Http::get('https://explorer.customtokenveo.xyz/api/v2/addresses/'. $address .'/transactions?filter=to');
        return $response->json();
    }

    public function getBalance($address)
    {
        $response = Http::get( $this->nodeUrl.'/balance/' . $address);
        return $response->json();
    }

    public function getTokenBalance($address, $contract = null)
    {
        $response = Http::post($this->nodeTokenUrl . '/wallet/balance', [
            'address' => $address,
            'contract' => $contract,
        ]);

        if ($response->successful()) {

            $body = $response->json();

            if (isset($body['success']) && isset($body['message']) && $body['success']) {
                return ['status' => 'ok', 'message' => $body['message']];
            }

            return ['error' => 'wallet_error'];
        }

        return ['error' => 'server_error'];
    }

    public function transfer($from, $to, $amount, $privatekey)
    {
        try {
            $response = Http::post($this->nodeUrl . '/transfer', [
                'from' => $from,
                'to' => $to,
                'amount' => math_formatter($amount, 6),
                'privateKey' => $privatekey
            ]);
            $res = $response->json();

            if($response->status() >= 400) {
                return [
                    'status' => STATUS_VALIDATION_ERROR,
                    'message' => $res
                ];
            }
            return [
                'source' => "user",
                'txn' => $res['hash'],
                'message' => $res,
                'status' => 200
            ];
        } catch (\Exception $e) {
            Log::error('Transfer error: ' . $e->getMessage());
            return [
                'status' => STATUS_VALIDATION_ERROR,
                'message' => $e->getMessage()
            ];
        }

    }

    public function transferToken($withdrawal_id, $address, $amount, $contract = '')
    {
        $privateKey = Setting::get('customtoken.private_key');

        $decimals = 12;

        $amount = math_formatter($amount, $decimals);

        $response = Http::post($this->nodeTokenUrl . '/wallet/withdraw', [
            'id' => $withdrawal_id,
            'amount' => $amount,
            'address' => setting('customtoken.wallet'),
            'private_key' => $privateKey,
            'to' => $address,
            'fee' => false,
            'contract' => $contract,
        ]);

        $source = '';
        $message = generate_uuid();

        if($response->failed()) {
            $status = STATUS_VALIDATION_ERROR;
        } else {
            $source = $message;
            $status = STATUS_OK;
        }

        return [
            'status' => $status,
            'source' => $source,
            'message' => $message,
        ];
    }

    public function getTransaction($txn)
    {
        try{
            $response = Http::get($this->nodeUrl.'/transaction/' . $txn);
            return $response->json();
        }catch(\Exception $e){
            Log::error('Get transaction error: ' . $e->getMessage());
            return [
                'status' => STATUS_VALIDATION_ERROR,
                'message' => $e->getMessage()
            ];
        }
    }
}
