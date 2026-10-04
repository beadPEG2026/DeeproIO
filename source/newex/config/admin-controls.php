<?php

return [
    // Explicitly reviewed platform inventory accounts; a proposer may also use their own wallet.
    'funding_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('ADMIN_FUNDING_USER_IDS', ''))))),
    // Simulation controls must never change public production market prices.
    'simulation_controls' => env('ADMIN_SIMULATION_CONTROLS', false) && in_array(env('APP_ENV'), ['local', 'testing'], true),
    'receipt_rpc' => [
        'xlayer' => env('XLAYER_RECEIPT_RPC', 'https://rpc.xlayer.tech'),
        'ethereum' => env('ETH_RECEIPT_RPC', 'https://ethereum-rpc.publicnode.com'),
        'bnb' => env('BSC_RECEIPT_RPC', 'https://bsc-dataseed.binance.org'),
        'polygon' => env('POLYGON_RECEIPT_RPC', 'https://polygon-bor-rpc.publicnode.com'),
    ],
];
