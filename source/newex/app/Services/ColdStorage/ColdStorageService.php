<?php

namespace App\Services\ColdStorage;

use App\Models\ColdStorage\ColdStorage;
use App\Services\Wallet\WalletService;

class ColdStorageService {

    public function transferCheck($network, $amount, $currency) {

        $coldStorage = ColdStorage::where('status', true)->whereNull('cold_storage_transaction_id')->where('network_id', $network)->where('cold_min_balance_amount', '<=', $amount)->where('currency_id', $currency)->first();

        if(!$coldStorage) return;

        // Custody owns platform transfers; never borrow a user withdrawal or user 1's balance.
        return app(\App\Services\Custody\CustodyService::class)->cold($coldStorage->id);
    }
}
