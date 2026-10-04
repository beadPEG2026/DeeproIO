<?php

// Constants
const BNB_WITHDRAW_CONFIRMED = 'confirmed';
const BNB_WITHDRAW_FAILED = 'failed';

use Illuminate\Support\Facades\Http;

if (!function_exists('get_bnb_url')) {
    function get_bnb_url()
    {
        return config('app.bsc_bridge');
    }
}

/*
 * Get bnb request
 */
if (!function_exists('get_bnb_request')) {
    function get_bnb_request($uri, $params)
    {
        $params['license'] = setting('system-monitor.ping', false);

        $params['hash'] = md5(config('app.url') . $params['license']);

        $response = Http::get(get_bnb_url() .'/'. $uri, $params);

        return $response->json();
    }
}

/*
 * Get bnb Keys
 */
if (!function_exists('get_bnb_keys')) {
    function get_bnb_keys($key)
    {
        $settings = setting('bnb');

        $keys = [
            'wallet' => $settings['wallet'] ?? null,
            'private_key' => $settings['private_key'] ?? null
        ];

        return $keys[$key];
    }
}

