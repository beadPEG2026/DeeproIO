<?php
namespace App\Services\PaymentGateways\Coin\Solana\Api;

use App\Helpers\PaymentGateways\Solana\SolanaNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;
use ParagonIE\Sodium\Compat;

class SolanaGateway implements CoinGatewayInterface
{
    public $aplhabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
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
            return get_ethereum_request($uri, $params);
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    /**
     * Used to generate address wallet per currency
     * @return array
     */
    public function createSolAddress() {

        // Generate a new ed25519 keypair
        $keypair = Compat::crypto_sign_keypair();

        $privateKey = Compat::crypto_sign_secretkey($keypair);
        $publicKey = Compat::crypto_sign_publickey($keypair);

        $privateKeyHex = bin2hex($privateKey);

        $publicKeyBase58 = $this->base58_encode($publicKey);

        // Get new wallet address
        return ['address' => $publicKeyBase58, 'private_key' => $privateKeyHex];
    }

    public function getArrayedPrivateKey($privateKey) {
        $privateKeyArray = array_map('ord', str_split(hex2bin($privateKey)));
        return json_encode($privateKeyArray, JSON_PRETTY_PRINT);
    }

    /**
     * Used to get sol/spl balance
     * @return array
     */
    public function getBalance($address, $contract = null)
    {
        $route = $contract ? 'wallet.balance.spl' : 'wallet.balance.sol';

        $response = Http::connectTimeout(5)->timeout(20)->post(SolanaNodeHelper::route($route), [
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
        $decimals = $type == "sol" ? 12 : 8;

        $amount = math_formatter($amount, $decimals);

        $response = Http::connectTimeout(5)->timeout(20)->post(SolanaNodeHelper::route('wallet.withdraw') . '/' . $type, [
            'id' => $withdrawal_id,
            'amount' => $amount,
            'address' => setting('solana.wallet'),
            'private_key' => $this->jsonToHex(setting('solana.private_key')),
            'to' => $address,
            'fee' => false,
            'contract' => $contract,
        ]);

        $source = '';
        $message = SolanaNodeHelper::generate_uuid();

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
        $response = Http::connectTimeout(5)->timeout(20)->get(SolanaNodeHelper::route('wallet.create'));
        return $response->json();
    }

    public function isValidSolanaAddress($address) {

        // Base58 alphabet used by Solana
        $base58Alphabet = $this->aplhabet;

        // Check if address contains only valid base58 characters
        if (!preg_match('/^[' . $base58Alphabet . ']{32,44}$/', $address)) {
            return false;
        }

        // Decode base58 to binary
        $decoded = $this->base58_decode($address);

        // Valid Solana public key must be exactly 32 bytes
        return strlen($decoded) === 32;
    }

    // Convert public key to base58 (Solana uses base58 for addresses)
    public function base58_encode($data) {
        $alphabet = $this->aplhabet;
        $base_count = strlen($alphabet);
        $encoded = '';
        $leading = strlen($data) - strlen(ltrim($data, "\0"));
        $num = gmp_init(bin2hex($data) ?: '0', 16);

        while (gmp_cmp($num, 0) > 0) {
            list($num, $rem) = gmp_div_qr($num, $base_count);
            $encoded .= $alphabet[gmp_intval($rem)];
        }

        return str_repeat('1', $leading) . strrev($encoded);
    }

    // Base58 decode function
    public function base58_decode($input) {
        $alphabet = $this->aplhabet;
        $base = strlen($alphabet);
        $decoded = gmp_init(0, 10);

        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                return '';
            }
            $decoded = gmp_add(gmp_mul($decoded, $base), $pos);
        }

        $hex = gmp_strval($decoded, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        return str_repeat("\0", strspn($input, '1')) . (gmp_cmp($decoded, 0) === 0 ? '' : hex2bin($hex));
    }

    public function jsonToHex($key) {

        // Decode JSON into array
        $intArray = json_decode($key, true);

        // Convert integers to binary string
        $binary = '';
        foreach ($intArray as $int) {
            $binary .= chr($int);
        }

        // Convert binary to hex
        return bin2hex($binary);
    }
}
