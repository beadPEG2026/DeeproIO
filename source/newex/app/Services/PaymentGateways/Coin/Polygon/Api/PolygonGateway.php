<?php
namespace App\Services\PaymentGateways\Coin\Polygon\Api;

use App\Helpers\PaymentGateways\Polygon\PolygonNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use kornrunner\Keccak;
use Setting;
use Sop\CryptoEncoding\PEM;
use Sop\CryptoTypes\Asymmetric\EC\ECPrivateKey;

class PolygonGateway implements CoinGatewayInterface
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
            return get_polygon_request($uri, $params);
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createMaticAddress() {

        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1'
        ];

        $res = openssl_pkey_new($config);

        // Generate Private Key
        openssl_pkey_export($res, $priv_key);

        // Get The Public Key
        $key_detail = openssl_pkey_get_details($res);
        $pub_key = $key_detail["key"];

        $priv_pem = PEM::fromString($priv_key);
        $ec_priv_key = ECPrivateKey::fromPEM($priv_pem);
        $ec_priv_seq = $ec_priv_key->toASN1();
        $priv_key_hex = bin2hex($ec_priv_seq->at(1)->asOctetString()->string());
        $pub_key_hex = bin2hex($ec_priv_seq->at(3)->asTagged()->asExplicit()->asBitString()->string());
        $pub_key_hex_2 = substr($pub_key_hex, 2);

        $hash = Keccak::hash(hex2bin($pub_key_hex_2), 256);

        $wallet_address = '0x' . substr($hash, -40);
        $wallet_private_key = '0x' . $priv_key_hex;

        // Get new wallet address
        return ['address' => $wallet_address, 'private_key' => $wallet_private_key];
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createMatic20Address() {
        return $this->creatematicAddress();
    }

    /**
     * Used to get matic/matic20 balance
     * @return array
     */
    public function getBalance($address, $contract = null)
    {
        $route = $contract ? 'wallet.balance.matic20' : 'wallet.balance.matic';

        $response = Http::connectTimeout(5)->timeout(20)->post(PolygonNodeHelper::route($route), [
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
     * @param $withdrawal_id
     * @param $amount
     * @return array
     */
    public function withdraw($type, $withdrawal_id, $address, $amount, $contract = '')
    {
        $decimals = $type == "matic" ? 6 : 12;

        $amount = math_formatter($amount, $decimals);

        $response = Http::connectTimeout(5)->timeout(20)->post(PolygonNodeHelper::route('wallet.withdraw') . '/' . $type, [
            'id' => $withdrawal_id,
            'amount' => $amount,
            'address' => setting('polygon.wallet'),
            'private_key' => setting('polygon.private_key'),
            'to' => $address,
            'fee' => false,
            'contract' => $contract,
        ]);

        $source = '';
        $message = PolygonNodeHelper::generate_uuid();

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
        $response = Http::connectTimeout(5)->timeout(20)->get(PolygonNodeHelper::route('wallet.create'));
        return $response->json();
    }
}
