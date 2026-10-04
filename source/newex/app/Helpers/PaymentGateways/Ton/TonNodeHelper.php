<?php

namespace App\Helpers\PaymentGateways\Ton;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TonNodeHelper {

    public static $routes = [
        'wallet.create' => 'wallet/create',
        'wallet.withdraw' => 'wallet/withdraw',
        'wallet.withdraw.ton' => 'wallet/withdraw/ton',
        'wallet.balance.ton' => 'wallet/balance/ton',
        'wallet.validate.ton' => 'wallet/validate/ton',
        'wallet.transactions.ton' => 'wallet/transactions/ton',
        // Merchant module endpoints (no platform DB updates)
        'wallet.transfer.ton' => 'wallet/transfer/ton',
    ];

    public static function route($action) {
        return config('app.ton_bridge') . '/' . self::$routes[$action];
    }

    public static function generate_uuid()
    {
        return Str::uuid();
    }

    public static function request($client, $url, $params) {
        return Http::post($url, $params);
    }
}
