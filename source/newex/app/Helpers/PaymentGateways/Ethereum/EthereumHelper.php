<?php

// Constants
const ETHEREUM_WITHDRAW_CONFIRMED = 'confirmed';
const ETHEREUM_WITHDRAW_FAILED = 'failed';

use Illuminate\Support\Facades\Http;

if (!function_exists('get_ethereum_infura_url')) {
    function get_ethereum_infura_url()
    {
        return config('app.ethereum_bridge');
    }
}

/*
 * Get Ethereum request
 */
if (!function_exists('get_ethereum_request')) {
    function get_ethereum_request($uri, $params)
    {
        $params['license'] = setting('system-monitor.ping', false);

        $params['hash'] = md5(config('app.url') . $params['license']);

        $response = Http::get(get_ethereum_infura_url() .'/'. $uri, $params);

        return $response->json();
    }
}

/*
 * Get Ethereum Keys
 */
if (!function_exists('get_ethereum_keys')) {
    function get_ethereum_keys($key)
    {
        $settings = setting('ethereum');

        $keys = [
            'wallet' => $settings['wallet'] ?? null,
            'private_key' => $settings['private_key'] ?? null
        ];

        return $keys[$key];
    }
}

