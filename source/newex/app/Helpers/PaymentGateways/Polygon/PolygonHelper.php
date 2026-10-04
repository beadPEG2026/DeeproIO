<?php

// Constants
const POLYGON_WITHDRAW_CONFIRMED = 'confirmed';
const POLYGON_WITHDRAW_FAILED = 'failed';

use Illuminate\Support\Facades\Http;

if (!function_exists('get_polygon_infura_url')) {
    function get_polygon_infura_url()
    {
        return config('app.polygon_bridge');
    }
}

/*
 * Get polygon request
 */
if (!function_exists('get_polygon_request')) {
    function get_polygon_request($uri, $params)
    {
        $params['license'] = setting('system-monitor.ping', false);

        $params['hash'] = md5(config('app.url') . $params['license']);

        $response = Http::get(get_polygon_infura_url() .'/'. $uri, $params);

        return $response->json();
    }
}

/*
 * Get polygon Keys
 */
if (!function_exists('get_polygon_keys')) {
    function get_polygon_keys($key)
    {
        $settings = setting('polygon');

        $keys = [
            'wallet' => $settings['wallet'] ?? null,
            'private_key' => $settings['private_key'] ?? null
        ];

        return $keys[$key];
    }
}

