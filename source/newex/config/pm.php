<?php

return [
    'services_enabled' => env('PM_SERVICE_WATCHER_ENABLED', true),
    'markets_enabled' => env('PM_MARKET_WATCHER_ENABLED', true),
    /*
    |--------------------------------------------------------------------------
    | PM2 / Supervisor Home Path
    |--------------------------------------------------------------------------
    */
    'home_path' => 'HOME=' . env('PM_PATH', '/var/www'),

    'php_path' => env('PM_PHP_PATH', '/www/server/php/83/bin/php'),

    'usr_path' => env('PM_USR_PATH', '/usr/local/bin:/usr/bin:/bin'),

    /*
    |--------------------------------------------------------------------------
    | Services to Watch
    |--------------------------------------------------------------------------
    */
    'services' => [
        [
            'name' => 'market-watcher',
            'command' => 'market-watcher:stats',
        ],

        [
            'name' => 'horizon',
            'command' => 'horizon',
        ],

        [
            'name' => 'order-processor',
            'command' => 'market:order-process',
        ],

        [
            'name' => 'futures-processor',
            'command' => 'market-watcher:liquidation',
            'plan' => 5,
        ],

        [
            'name' => 'futures-limit-processor',
            'command' => 'futures:limit-order-process',
            'plan' => 5,
        ],

        [
            'name' => 'futures-tpsl-process',
            'command' => 'futures:tpsl-process',
            'plan' => 5,
        ],

        [
            'name' => 'peer-order-processor',
            'command' => 'peer-order:watcher',
            'plan' => 5,
        ],

        [
            'name' => 'options-processor',
            'command' => 'options-watcher:process',
            'plan' => 5,
        ],

    ]
];
