<?php
namespace App\Services\Wallet;

use App\Models\Currency\Currency;
use App\Services\Deposit\DepositChannelPolicy;

/** Reuse the same policies as wallet selectors; never infer availability from a label. */
final class AssetAvailability
{
    public function forSymbol(string $symbol, ?int $userId): array
    {
        $currency = Currency::where('symbol', $symbol)->with('networks')->first();
        $deposit = $withdraw = false;
        if ($currency) foreach ($currency->networks as $network) {
            $deposit = $deposit || app(DepositChannelPolicy::class)->error($currency->id, $network->id, true, $userId) === null;
            $withdraw = $withdraw || app(WithdrawalNetworkPolicy::class)->error($currency->id, $network->id) === null;
        }
        return ['deposit' => $deposit, 'withdraw' => $withdraw];
    }
}
