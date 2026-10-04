<?php

namespace App\Helpers\PaymentGateways\Tron;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TronNodeHelper {

    public static $routes = [
        'wallet.create' => 'wallet/create',
        'wallet.withdraw' => 'wallet/withdraw',
        'wallet.transfer.main.to.wallet' => 'wallet/transfer/to/main',
        'wallet.transfer.main.to.wallet.trc' => 'wallet/transfer/to/main/wallet/trc',
        'wallet.balance.trx' => 'wallet/balance/trx',
        'wallet.balance.trc' => 'wallet/balance/trc',
        'blockchain.latest.block' => 'blockchain/latest/block',
        'contract.register' => 'contract/register',
        // Merchant module endpoints (no platform DB updates)
        'wallet.transfer.trx' => 'wallet/transfer/trx',
        'wallet.transfer.trc' => 'wallet/transfer/trc',
        'wallet.merchant.sweep.trx' => 'wallet/merchant/sweep/trx',
        'wallet.merchant.sweep.trc' => 'wallet/merchant/sweep/trc',
    ];

    public static function route($action) {
        return config('app.tron_bridge') . '/' . self::$routes[$action];
    }

    public static function generate_uuid()
    {
        return Str::uuid();
    }

    public static function request($client, $url, $params) {
        return Http::post($url, $params);
    }
}
