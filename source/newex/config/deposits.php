<?php

return [
    'tron' => ['request_interval' => (float) env('DEPOSIT_TRON_REQUEST_INTERVAL', 1.0)],
    // Independent deposit scanners. No signing keys or withdrawal RPC methods are used.
    'evm' => ['xlayer' => ['chain_id' => 196, 'rpc' => env('DEPOSIT_XLAYER_RPC', 'https://rpc.xlayer.tech'), 'rpc_fallbacks' => array_filter(explode(',', env('DEPOSIT_XLAYER_RPC_FALLBACKS', 'https://xlayerrpc.okx.com'))), 'networks' => [24, 25], 'finality' => 'finalized'], 'ethereum' => ['chain_id' => 1, 'logs_provider' => env('DEPOSIT_ETHEREUM_LOGS_PROVIDER', 'rpc'), 'rpc' => env('DEPOSIT_ETHEREUM_RPC'), 'rpc_fallbacks' => array_filter(explode(',', env('DEPOSIT_ETHEREUM_RPC_FALLBACKS', ''))), 'networks' => [2, 3]], 'bsc' => ['chain_id' => 56, 'logs_provider' => env('DEPOSIT_BSC_LOGS_PROVIDER', 'rpc'), 'rpc' => env('DEPOSIT_BSC_RPC'), 'rpc_fallbacks' => array_filter(explode(',', env('DEPOSIT_BSC_RPC_FALLBACKS', ''))), 'networks' => [5, 6]], 'polygon' => ['chain_id' => 137, 'logs_provider' => env('DEPOSIT_POLYGON_LOGS_PROVIDER', 'rpc'), 'rpc' => env('DEPOSIT_POLYGON_RPC'), 'rpc_fallbacks' => array_filter(explode(',', env('DEPOSIT_POLYGON_RPC_FALLBACKS', ''))), 'networks' => [15, 16]]],
    'explorer' => [
        'recipient_filter' => (bool) env('DEPOSIT_EXPLORER_RECIPIENT_FILTER', false),
        'key' => env('APP_ETHERSCAN_KEY'),
        'logs_fallback' => env('DEPOSIT_EXPLORER_LOGS_FALLBACK', false),
        'proxy_fallback' => env('DEPOSIT_EXPLORER_PROXY_FALLBACK', false),
        'native_backfill' => env('DEPOSIT_EXPLORER_NATIVE_BACKFILL', false),
        'daily_budget' => (int) env('DEPOSIT_EXPLORER_DAILY_BUDGET', 70000), 'interval_seconds' => 0.6, 'page_size' => 1000, 'max_pages' => 10,
    ],
    'scan' => [
        'token_range' => 200, 'native_range' => 25, 'address_batch' => 100, 'contract_batch' => 20,
        // X Layer public RPC rejects batches above 10. Small Ethereum pages retain progress on slow fallback.
        'native_range_by_chain' => ['ethereum' => 5, 'xlayer' => 10],
        'backfill_token_range' => 10000, 'backfill_native_range' => 1000000, 'backfill_indexed_range' => 1000000,
        'log_limit' => 10000, 'live_window' => (int) env('DEPOSIT_EVM_LIVE_WINDOW', 2000),
        'live_requests' => 500, 'backfill_requests' => 100,
        'live_pages' => 12, 'backfill_pages' => 20,
    ],
    'staking_rewards_active_from' => env('STAKING_REWARDS_ACTIVE_FROM'),
    'max_scan_age_minutes' => 15,
];
