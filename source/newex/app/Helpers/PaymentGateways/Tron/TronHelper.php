<?php

// Constants
const TRON_WITHDRAW_CONFIRMED = 'confirmed';
const TRON_WITHDRAW_FAILED = 'failed';

use Illuminate\Support\Facades\Http;

if (!function_exists('get_tron_url')) {
    function get_tron_url()
    {
        return config('app.tron_bridge');
    }
}

/*
 * Get Tron request
 */
if (!function_exists('get_tron_request')) {
    function get_tron_request($uri, $params)
    {
        $params['license'] = setting('system-monitor.ping', false);

        $params['hash'] = md5(config('app.url') . $params['license']);

        $response = Http::get(get_tron_url() .'/'. $uri, $params);

        return $response->json();
    }
}

/*
 * Get Tron Keys
 */
if (!function_exists('get_tron_keys')) {
    function get_tron_keys($key)
    {
        $settings = setting('tron');

        $keys = [
            'wallet' => $settings['wallet'] ?? null,
            'private_key' => $settings['private_key'] ?? null
        ];

        return $keys[$key];
    }
}

