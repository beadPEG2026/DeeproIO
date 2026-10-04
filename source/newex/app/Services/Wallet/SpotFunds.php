<?php
namespace App\Services\Wallet;
use App\Models\Wallet\Wallet;
final class SpotFunds {
    public static function field(Wallet $wallet): string {
        // Keep legacy virtual balances isolated, including a virtual account whose balance reaches zero.
        return $wallet->user?->is_xn || bccomp((string)($wallet->balance_in_virtual_trade??0),'0',18)>0
            ? 'balance_in_virtual_trade' : 'balance_in_trade';
    }
}
