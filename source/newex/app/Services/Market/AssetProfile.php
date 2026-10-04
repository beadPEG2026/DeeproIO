<?php
namespace App\Services\Market;

use App\Models\Currency\Currency;
use Illuminate\Validation\ValidationException;

/** Shared asset identity and network restrictions; never an execution engine. */
final class AssetProfile
{
    public static function reference(object $currency): array
    {
        $value = $currency->asset_reference ?? null;
        return is_array($value) ? $value : (json_decode($value ?: '{}', true) ?: []);
    }

    public static function bscOnly(object $currency): bool
    {
        if (HongKongPriceProduct::isCurrency($currency)) return false;
        return in_array($currency->asset_category ?? '', ['stock', 'etf'], true)
            || self::verifiedReference(self::reference($currency));
    }

    private static function verifiedReference(array $reference): bool
    {
        return isset($reference['tokenId']) || ($reference['listingTemplate'] ?? null) === 'binance-bstocks-v1';
    }

    public static function networkError(object $currency, string $slug): ?string
    {
        if (HongKongPriceProduct::isCurrency($currency)) return 'Price reference products do not support on-chain transfers';
        return self::bscOnly($currency) && $slug !== 'bep20'
            ? 'This asset supports BSC (BEP20) only' : null;
    }

    public static function providerMetadata(array $asset): array
    {
        return array_diff_key($asset, array_flip([
            'id', 'symbol', 'name', 'issuer', 'decimals', 'contract', 'chain', 'chainId', 'assetType',
        ]));
    }

    public static function presentation(object $currency): array
    {
        if (HongKongPriceProduct::isCurrency($currency)) {
            return array_merge(self::reference($currency), [
                'id'=>$currency->symbol, 'symbol'=>$currency->symbol, 'name'=>$currency->name,
                'issuer'=>$currency->asset_issuer, 'unit'=>'product_unit', 'assetType'=>'stock',
                'chain'=>null, 'chainId'=>null, 'contract'=>null, 'explorerUrl'=>null,
                'decimals'=>(int)$currency->decimals,
            ]);
        }
        return array_merge(self::reference($currency), [
            'id' => $currency->symbol, 'symbol' => $currency->symbol,
            'name' => $currency->name, 'issuer' => $currency->asset_issuer,
            'unit' => $currency->asset_unit, 'assetType' => $currency->asset_category,
            'chain' => 'BSC', 'chainId' => 56, 'contract' => strtolower($currency->bep_contract),
            'decimals' => (int) $currency->decimals,
            'explorerUrl' => 'https://bscscan.com/token/'.$currency->bep_contract,
        ]);
    }

    /** Client forms cannot replace verified provider identity or silently rename ledger assets. */
    public static function validateEdit(?Currency $currency, array $data): void
    {
        if ($currency && HongKongPriceProduct::isCurrency($currency)) {
            foreach (['symbol','decimals','contract','bep_contract','trc_contract','sol_contract','matic_contract','xlayer_contract','custom_contract','type'] as $field) {
                if (array_key_exists($field,$data) && (string)$data[$field] !== (string)$currency->$field)
                    self::fail($field, 'Verified asset identity cannot be changed here');
            }
            if (($data['asset_category'] ?? 'stock') !== 'stock') self::fail('asset_category','Keep the stock or ETF asset category');
            if (!empty($data['networks'])) self::fail('networks','Price reference products do not support on-chain transfers');
            foreach (['deposit_status','withdraw_status'] as $key)
                if (in_array($data[$key] ?? false,[true,1,'1'],true)) self::fail($key,'Price reference products do not support on-chain transfers');
            return;
        }
        $category = $data['asset_category'] ?? $currency?->asset_category ?? 'crypto';
        $reference = $currency ? self::reference($currency) : [];
        if (self::verifiedReference($reference)) {
            foreach (['symbol', 'decimals', 'bep_contract', 'type'] as $field) {
                if (array_key_exists($field, $data) && strtolower((string)$data[$field]) !== strtolower((string)$currency->$field)) {
                    self::fail($field, 'Verified asset identity cannot be changed here');
                }
            }
            // Symbols are case-sensitive in existing market routes and external adapters.
            if (isset($data['symbol']) && $data['symbol'] !== $currency->symbol) self::fail('symbol', 'Verified asset identity cannot be changed here');
            if (!in_array($category, ['stock','etf'], true)) self::fail('asset_category', 'Keep the stock or ETF asset category');
        } elseif (in_array($category, ['stock','etf'], true)) {
            self::fail('asset_category', 'Use the stock quick listing entry to verify this asset first');
        }
        if (in_array($category, ['stock','etf'], true) || ($currency && self::bscOnly($currency))) {
            $ids = array_values(array_unique(array_map('intval', $data['networks'] ?? [])));
            if ($ids !== [NETWORK_BEP]) self::fail('networks', 'This asset supports BSC (BEP20) only');
        }
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
