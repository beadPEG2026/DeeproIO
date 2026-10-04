<?php

namespace App\Helpers\PaymentGateways\Polygon;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PolygonNodeHelper {

    public static $routes = [
        'wallet.create' => 'wallet/create',
        'wallet.withdraw' => 'wallet/withdraw',
        'wallet.transfer.main.to.wallet' => 'wallet/transfer/to/main',
        'wallet.transfer.main.to.wallet.matic20' => 'wallet/transfer/to/main/wallet/matic20',
        'wallet.balance.matic' => 'wallet/balance/matic',
        'wallet.balance.matic20' => 'wallet/balance/matic20',
        'blockchain.latest.block' => 'blockchain/latest/block',
        'contract.register' => 'contract/register',
        // Merchant module endpoints (no platform DB updates)
        'wallet.transfer.matic' => 'wallet/transfer/matic',
        'wallet.transfer.matic20' => 'wallet/transfer/matic20',
        'wallet.merchant.sweep.matic' => 'wallet/merchant/sweep/matic',
        'wallet.merchant.sweep.matic20' => 'wallet/merchant/sweep/matic20',
    ];

    public static function route($action) {
        return config('app.polygon_bridge') . '/' . self::$routes[$action];
    }

    public static function generate_uuid()
    {
        return Str::uuid();
    }

    public static function request($client, $url, $params) {
        return Http::post($url, $params);
    }
}
