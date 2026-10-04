<?php

namespace App\Helpers\PaymentGateways\Solana;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SolanaNodeHelper {

    public static $routes = [
        'wallet.create' => 'wallet/create',
        'wallet.withdraw' => 'wallet/withdraw',
        'wallet.transfer.main.to.wallet' => 'wallet/transfer/to/main',
        'wallet.transfer.main.to.wallet.spl' => 'wallet/transfer/to/main/wallet/spl',
        'wallet.balance.sol' => 'wallet/balance/sol',
        'wallet.balance.spl' => 'wallet/balance/spl',
        'blockchain.latest.block' => 'blockchain/latest/block',
        'contract.register' => 'contract/register',
        // Merchant module endpoints (no platform DB updates)
        'wallet.transfer' => 'wallet/transfer',
        'wallet.transfer.spl' => 'wallet/transfer/spl',
        'wallet.merchant.sweep.sol' => 'wallet/merchant/sweep/sol',
        'wallet.merchant.sweep.spl' => 'wallet/merchant/sweep/spl',
    ];

    public static function route($action) {
        return config('app.solana_bridge') . '/' . self::$routes[$action];
    }

    public static function generate_uuid()
    {
        return Str::uuid();
    }

    public static function request($client, $url, $params) {
        return Http::post($url, $params);
    }
}
