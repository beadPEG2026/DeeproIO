<?php
// Supported Chains
/*
 *  ETH = 2
 *  ERC20 = 3
 *  BNB = 5
 *  BEP20 = 6
 *  TRX = 7
 *  TRC-20 = 8
 *  BTC = 9
 *  POLYGON = 15
 *  MATIC20 = 16
 *
 *
*/
return [
    'base_url' => env('FIREBLOCKS_BASE_URL', 'https://sandbox-api.fireblocks.io'),
    'api_key' => env('FIREBLOCKS_API_KEY', ''),
    'secret_key' => env('FIREBLOCKS_PUBLIC_KEY_PATH', base_path('fireblocks_secret.key')),
    'hot_wallet_vault' => env('FIREBLOCKS_EVM_HOT_WALLET_VAULT', 0),
    'hot_wallet_evm' => env('FIREBLOCKS_EVM_HOT_WALLET', ''),
    'hot_wallet_evm_id' => env('FIREBLOCKS_EVM_HOT_WALLET_ID', 0),
    'networks' => [
         2 => [
            'ETH' => 'ETH_TEST3'
         ],
         5 => [
           'BNB' => 'BNB_TEST',
         ],
         6 => [
             'USDT' => 'USDT_BSC_TEST'
         ],
         9 => [
             'BTC' => 'BTC_TEST'
        ]
    ]
];
