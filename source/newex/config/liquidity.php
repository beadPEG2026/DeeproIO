<?php

return [
    // Public quotes only. Signing and order execution retain their own endpoints.
    'market_data_base' => env('BINANCE_MARKET_DATA_BASE', 'https://data-api.binance.vision'),
    'api_key' => env('BINANCE_API_KEY'),
    'api_secret' => env('BINANCE_API_SECRET'),
    'testnet' => env('BINANCE_API_TESTNET', false),
    'market_stream' => env('BINANCE_MARKET_STREAM', 'wss://stream.binance.com:9443/ws/'),
];
