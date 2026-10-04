<?php

return [
    // This enables only the explicitly labelled sandbox; no real wallet or chain
    // adapter can be selected from this setting. AWS never receives this flag.
    'local_acceptance' => env('UMI_V2_LOCAL_ACCEPTANCE', false),
    // A separate deployment switch; live_settings keeps every operation off
    // until wallet, custody, address, quote and operator checks pass.
    'funded_enabled' => env('UMI_V2_FUNDED_ENABLED', false),
    'umi_usd_quote' => '1',
    'share_usd_quote' => '0.1',
    'points_value_factor' => '0.1',
    'static_rate' => '0.01',
    'timezone' => 'Asia/Shanghai',
    'sandbox_initial_supply_umi' => '100000000',
    'sandbox_terminal_floor_umi' => '21000000',
];
