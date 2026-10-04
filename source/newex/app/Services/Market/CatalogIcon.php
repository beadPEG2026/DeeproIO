<?php
namespace App\Services\Market;

use App\Models\Currency\Currency;

final class CatalogIcon
{
    public static function path(Currency $currency): ?string
    {
        $icon=config('catalog-icons.'.$currency->symbol);
        if (!$icon || !is_file(public_path($icon['path']))) return null;
        if (isset($icon['securityCode'])) {
            $asset=AssetProfile::reference($currency);
            return HongKongPriceProduct::isAsset($asset) && ($asset['securityCode']??null)===$icon['securityCode'] ? $icon['path'] : null;
        }
        $fields=['ETH'=>'contract','BSC'=>'bep_contract','MATIC'=>'matic_contract','SOL'=>'sol_contract','TRX'=>'trc_contract'];
        foreach ($icon['identities']??[] as $identity) {
            if ($identity['native']) {
                if (!$currency->is_token && !$currency->contract && !$currency->bep_contract && !$currency->trc_contract && !$currency->sol_contract && !$currency->matic_contract) return $icon['path'];
            } elseif (isset($fields[$identity['chain']])) {
                $actual=(string)$currency->{$fields[$identity['chain']]};$expected=$identity['contract'];
                if (str_starts_with($expected,'0x') ? strtolower($actual)===strtolower($expected) : $actual===$expected) return $icon['path'];
            }
        }
        return null;
    }
}
