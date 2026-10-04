<?php
namespace App\Services\Wallet;

use App\Models\Currency\Currency;
use App\Services\Deposit\DepositChannelPolicy;

/** Public explanations share the request policies, without exposing scanner credentials or internals. */
final class NetworkAvailability
{
    public function forCurrency(Currency $currency, string $purpose, ?int $userId): array
    {
        $rows = [];
        foreach ($currency->networks as $network) {
            if (AssetNetworkOptions::nativeMismatch($currency, $network->slug)) continue;
            $error = $purpose === 'withdraw'
                ? app(WithdrawalNetworkPolicy::class)->error($currency->id, $network->id)
                : app(DepositChannelPolicy::class)->error($currency->id, $network->id, true, $userId);
            [$code, $message] = self::reason($error);
            $rows[] = ['id' => $network->id, 'name' => $network->id === NETWORK_COINPAYMENTS ? $currency->coinpayments_description : $network->name,
                'available' => $error === null, 'code' => $code, 'message' => __($message)];
        }
        return $rows;
    }

    public static function reason(?string $error): array
    {
        if ($error === null) return ['available', 'Available'];
        if (in_array($error, ['Deposits are unavailable','Withdrawals are not allowed for this currency','Withdrawals are not allowed for this network'])) return ['paused', 'This channel is temporarily paused.'];
        if ($error === 'Deposit scanner needs attention') return ['maintenance', 'This network is being synchronized. Please try again later.'];
        if ($error === 'Deposit channel is awaiting acceptance') return ['preparing', 'This channel is being prepared.'];
        if (in_array($error, ['This asset supports BSC (BEP20) only','This network is not supported for the selected currency','Asset is not linked to this network'])) return ['unsupported', 'This asset is not supported on this network.'];
        return ['maintenance', 'This channel is under maintenance. Please contact support if needed.'];
    }
}
