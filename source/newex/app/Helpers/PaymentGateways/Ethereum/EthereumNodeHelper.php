<?php

namespace App\Helpers\PaymentGateways\Ethereum;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EthereumNodeHelper {

    public static $routes = [
        'wallet.create' => 'wallet/create',
        'wallet.withdraw' => 'wallet/withdraw',
        'wallet.transfer.main.to.wallet' => 'wallet/transfer/to/main',
        'wallet.transfer.main.to.wallet.erc' => 'wallet/transfer/to/main/wallet/erc',
        'wallet.balance.eth' => 'wallet/balance/eth',
        'wallet.balance.erc' => 'wallet/balance/erc',
        'blockchain.latest.block' => 'blockchain/latest/block',
        'contract.register' => 'contract/register',
        // Merchant module endpoints (no platform DB updates)
        'wallet.transfer.eth' => 'wallet/transfer/eth',
        'wallet.transfer.erc' => 'wallet/transfer/erc',
        'wallet.merchant.sweep.eth' => 'wallet/merchant/sweep/eth',
        'wallet.merchant.sweep.erc' => 'wallet/merchant/sweep/erc',
    ];

    public static function route($action) {
        return config('app.ethereum_bridge') . '/' . self::$routes[$action];
    }

    public static function generate_uuid()
    {
        return Str::uuid();
    }

    public static function request($client, $url, $params) {
        return Http::post($url, $params);
    }
}
