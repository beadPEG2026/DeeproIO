<?php
namespace App\Services\PaymentGateways\Coin\Bnb\Api;

use App\Helpers\PaymentGateways\Bnb\BnbNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use App\Services\PaymentGateways\Coin\Ethereum\Api\EthereumGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class BnbGateway implements CoinGatewayInterface
{
    /**
     * Used to call request to Coinpayments API
     *
     * @param $uri
     * @param array $params
     * @return false|mixed
     */
    public function request($uri, $params = [])
    {
        try {
            return get_bnb_request($uri, $params);
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createBnbAddress() {
        return (new EthereumGateway())->createEthAddress();
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createBepAddress() {
        return $this->createBnbAddress();
    }

    /**
     * Used to get network confirmations by hash+
     * @return array
     */
    public function getNetworkConfirmations($hash, $type) {
        // Get network confirmation by hash
        return $this->request('node/blockchain/confirmations', ['hash' => $hash, 'type' => $type]);
    }

    /**
     * Used to get bnb/bep balance
     * @return array
     */
    public function getBalance($address, $contract = null)
    {
        $route = $contract ? 'wallet.balance.bep' : 'wallet.balance.bnb';

        $response = Http::connectTimeout(5)->timeout(20)->post(BnbNodeHelper::route($route), [
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
        $decimals = $type == "bnb" ? 8 : 12;

        $amount = math_formatter($amount, $decimals);

        $response = Http::connectTimeout(5)->timeout(20)->post(BnbNodeHelper::route('wallet.withdraw') . '/' . $type, [
            'id' => $withdrawal_id,
            'amount' => $amount,
            'address' => setting('bnb.wallet'),
            'address_private_key' => setting('bnb.private_key'),
            'private_key' => setting('bnb.private_key'),
            'to' => $address,
            'fee' => false,
            'contract' => $contract,
        ]);

        $source = '';
        $message = BnbNodeHelper::generate_uuid();

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

    public function registerSettings() {
        return $this->request('node/wallet/register', Setting::get('bnb'));
    }

    public function ping() {
        $response = Http::connectTimeout(5)->timeout(20)->get(BnbNodeHelper::route('wallet.create'));
        return $response->json();
    }
}
