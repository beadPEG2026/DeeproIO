<?php

return [
    'enabled' => env('SUMSUB_ENABLED'),
    'token' => env('SUMSUB_APP_TOKEN'),
    'secret' => env('SUMSUB_APP_SECRET'),
    'webhook_secret' => env('SUMSUB_WEBHOOK_SECRET'),
    'kyc_level' => env('SUMSUB_KYC_LEVEL')
];
