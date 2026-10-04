<?php

namespace App\Helpers\PaymentGateways\Bnb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BnbNodeHelper {

    public static $routes = [
        'wallet.create' => 'wallet/create',
        'wallet.withdraw' => 'wallet/withdraw',
        'wallet.transfer.main.to.wallet' => 'wallet/transfer/to/main',
        'wallet.transfer.main.to.wallet.bep' => 'wallet/transfer/to/main/wallet/bep',
        'wallet.balance.bnb' => 'wallet/balance/bnb',
        'wallet.balance.bep' => 'wallet/balance/bep',
        'blockchain.latest.block' => 'blockchain/latest/block',
        'contract.register' => 'contract/register',
        // Merchant module endpoints (no platform DB updates)
        'wallet.transfer.bnb' => 'wallet/transfer/bnb',
        'wallet.transfer.bep' => 'wallet/transfer/bep',
        'wallet.merchant.sweep.bnb' => 'wallet/merchant/sweep/bnb',
        'wallet.merchant.sweep.bep' => 'wallet/merchant/sweep/bep',
    ];

    public static function route($action) {
        return config('app.bsc_bridge') . '/' . self::$routes[$action];
    }

    public static function generate_uuid()
    {
        return Str::uuid();
    }

    public static function request($client, $url, $params) {
        return Http::post($url, $params);
    }
}
