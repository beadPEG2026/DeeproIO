<?php

// Constants
const COINPAYMENTS_DEPOSIT_CONFIRMED = 100;
const COINPAYMENTS_WITHDRAW_CONFIRMED = 2;
const COINPAYMENTS_WITHDRAW_FAILED = 0;
const COINPAYMENTS_AUTO_CONFIRM = 1;

// Plain Coinpayments Helper Functions

/*
 * Get Coinpayments base url
 */

use GuzzleHttp\Client;

if (!function_exists('get_coinpayments_base_url')) {
    function get_coinpayments_base_url()
    {
        return 'https://a-api.coinpayments.net/api/v2';
        //return 'https://www.coinpayments.net/api.php';
    }
}

/*
 * Get Coinpayments content type
 */
if (!function_exists('get_coinpayments_request_content_type')) {
    function get_coinpayments_request_content_type()
    {
        return 'application/json';
    }
}

/*
 * Get Coinpayments signed hmac request
 */
if (!function_exists('get_coinpayments_hmac_request')) {
    function get_coinpayments_hmac_request($query, $key)
    {
        return hash_hmac('sha512', http_build_query($query), $key);
    }
}

/*
 * Get Coinpayments request
 */
if (!function_exists('get_coinpayments_request')) {
    function get_coinpayments_request($uri, $params, $type = 'POST')
    {
        $endpoint = get_coinpayments_base_url() . '/' . $uri;

        if($type === 'GET') {
            return get_coinpayments_response((new Client())->get($endpoint, [
                'form_params' => $params,
            ]));
        }

        $date = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');

        $payload = !empty($params) ? json_encode($params) : '';

        return get_coinpayments_response((new Client())->post($endpoint, [
            'body' => $payload,
            'headers' => [
                'X-CoinPayments-Client' => get_coinpayments_keys('public_key'),
                'X-CoinPayments-Timestamp' => $date,
                'X-CoinPayments-Signature' => calculateSignature($type, $endpoint, $payload, $date, get_coinpayments_keys('public_key'), get_coinpayments_keys('private_key')),
                'Content-Type' => get_coinpayments_request_content_type(),
            ]
        ]));
    }
}

/*
 * Get calculateSignature
 */
if (!function_exists('calculateSignature')) {
    function calculateSignature(string $method, string $url, string $payload, string $date, $clientId, $secret): string
    {
        $signatureString = implode('', [chr(239), chr(187), chr(191), $method, $url, $clientId, $date, $payload]);
        return base64_encode(hash_hmac('sha256', $signatureString, $secret, true));
    }
}
/*
 * Get Coinpayments format
 */
if (!function_exists('get_coinpayments_format')) {
    function get_coinpayments_format()
    {
        return 'json';
    }
}

/*
 * Get Coinpayments Api Version
 */
if (!function_exists('get_coinpayments_api_version')) {
    function get_coinpayments_api_version()
    {
        return '1';
    }
}

/*
 * Get Coinpayments Response
 */
if (!function_exists('get_coinpayments_response')) {
    function get_coinpayments_response($request)
    {
        return json_decode($request->getBody()->getContents());
    }
}

/*
 * Get Coinpayments Keys
 */
if (!function_exists('get_coinpayments_keys')) {
    function get_coinpayments_keys($key)
    {
        $settings = setting('coinpayments');

        $keys = [
            'public_key' => $settings['public_key'] ?? null,
            'private_key' => $settings['private_key'] ?? null
        ];

        return $keys[$key];
    }
}

