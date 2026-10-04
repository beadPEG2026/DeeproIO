<?php
return [
    'data_url' => env('STOCK_DATA_URL', 'http://127.0.0.1:8011'),
    'assets' => json_decode(file_get_contents(resource_path('data/stock-tokens.json')), true, 512, JSON_THROW_ON_ERROR)['assets'],
    'intervals' => ['1m', '5m', '15m', '1h', '4h', '1d'],
    'documents_enabled' => (bool) env('UMI_DOCUMENTS_ENABLED', true),
];
