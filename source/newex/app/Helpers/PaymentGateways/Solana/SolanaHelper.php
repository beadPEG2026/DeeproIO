<?php

// Constants
const SOLANA_WITHDRAW_CONFIRMED = 'confirmed';
const SOLANA_WITHDRAW_FAILED = 'failed';

use Illuminate\Support\Facades\Http;

if (!function_exists('get_solana_rpc_url')) {
    function get_solana_rpc_url()
    {
        return config('solana.rpc_endpoint');
    }
}

/*
 * Get Solana request
 */
if (!function_exists('get_solana_request')) {
    function get_solana_request($uri, $params)
    {
        $params['license'] = setting('system-monitor.ping', false);

        $params['hash'] = md5(config('app.url') . $params['license']);

        $response = Http::get(get_solana_rpc_url() .'/'. $uri, $params);

        return $response->json();
    }
}

/*
 * Get Solana Keys
 */
if (!function_exists('get_solana_keys')) {
    function get_solana_keys($key)
    {
        $settings = setting('solana');

        $keys = [
            'wallet' => $settings['wallet'] ?? null,
            'private_key' => $settings['private_key'] ?? null
        ];

        return $keys[$key];
    }
}

