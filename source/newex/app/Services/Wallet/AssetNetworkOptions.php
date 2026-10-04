<?php

namespace App\Services\Wallet;

use App\Models\Currency\Currency;
use App\Services\Custody\CustodyNetwork;
use App\Services\Market\AssetProfile;

/** Present the asset's existing route, never merge or reassign persisted network IDs. */
final class AssetNetworkOptions
{
    /** Only reject a known native route with a definitely different asset. */
    public static function nativeMismatch(object $currency, ?string $slug): bool
    {
        $native = ['eth'=>['ETH'], 'bnb'=>['BNB'], 'matic'=>['POL','MATIC'], 'xlayer'=>['OKB'],
            'sol'=>['SOL'], 'trx'=>['TRX'], 'btc'=>['BTC'], 'xrp'=>['XRP'], 'ton'=>['TON']];
        $symbol = strtoupper(trim((string) ($currency->symbol ?? '')));
        return $symbol !== '' && isset($native[$slug]) && !in_array($symbol, $native[$slug], true);
    }

    public static function forCurrency(Currency $currency, ?array $allowedIds = null): array
    {
        $byChain = [];
        foreach ($currency->networks as $network) {
            if ($allowedIds !== null && !in_array((int) $network->id, $allowedIds, true)) continue;
            $route = CustodyNetwork::MAP[$network->slug] ?? null;
            if (!$route || AssetProfile::networkError($currency, $network->slug)) continue;
            [$chain, $field, $nativeSymbol] = $route;
            $symbol = strtoupper((string) $currency->symbol);
            if ($field ? !trim((string) $currency->{$field}) : !in_array($symbol, $chain === 'polygon' ? ['POL', 'MATIC'] : [$nativeSymbol], true)) continue;
            // Legacy asset associations can contain both routes. A native asset uses
            // the native route on its home chain; tokens keep their contract route.
            $row = ['id' => (int) $network->id, 'name' => $network->name, 'chain' => $chain, 'kind' => $field ? 'token' : 'native'];
            if (!isset($byChain[$chain]) || !$field) $byChain[$chain] = $row;
        }
        return array_values($byChain);
    }
}
