<?php
namespace App\Services\PaymentGateways\Coin\Tron\Api;

use App\Helpers\PaymentGateways\Tron\TronNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use IEXBase\TronAPI\Tron;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TronGateway implements CoinGatewayInterface
{
    /**
     * Used to call request to Tron API
     *
     * @param $uri
     * @param array $params
     * @return false|mixed
     */
    public function request($uri, $params = [])
    {
        try {
            return get_tron_request($uri, $params);
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createTrxAddress() {

        $tron = new Tron();
        $generateAddress = $tron->generateAddress();

        return ['address' => $generateAddress->getAddress(true), 'private_key' => $generateAddress->getPrivateKey()];
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createTrcAddress() {
        return $this->createTrxAddress();
    }

    /**
     * Used to get eth/erc balance
     * @return array
     */
    public function getBalance($address, $contract = null)
    {
        $route = $contract ? 'wallet.balance.trc' : 'wallet.balance.trx';

        $response = Http::connectTimeout(5)->timeout(20)->post(TronNodeHelper::route($route), [
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

    /**
     * Used to generate data to withdraw
     * @param $address
     * @param $amount
     * @return array
     */
    public function withdraw($type, $withdrawal_id, $address, $amount, $contract = '')
    {
        $decimals = $type == "trx" ? 6 : 12;

        $amount = math_formatter($amount, $decimals);


        $response = Http::connectTimeout(5)->timeout(20)->post(TronNodeHelper::route('wallet.withdraw') . '/' . $type, [
            'id' => $withdrawal_id,
            'amount' => $amount,
            'address' => setting('tron.wallet'),
            'private_key' => setting('tron.private_key'),
            'to' => $address,
            'fee' => false,
            'contract' => $contract,
        ]);

        $source = '';
        $message = TronNodeHelper::generate_uuid();

        if(!\App\Services\Wallet\BridgeResponse::accepted($response)) {
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

    public function ping() {

        $response = Http::connectTimeout(5)->timeout(20)->post(TronNodeHelper::route('wallet.balance.trx'), [
            'address' => 'TETMxEFUaVrSHgMTVZCp9FsvKdxBkDbRwY',
            'contract' => null,
        ]);

        return $response->json();
    }
}
