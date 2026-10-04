<?php
namespace App\Services\Wallet;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WithdrawalNetworkPolicy
{
    public function error(int $currencyId, int $networkId, bool $internal = false): ?string
    {
        $currency = DB::table('currencies')->where('id',$currencyId)->whereNull('deleted_at')->first();
        $network = DB::table('networks')->where('id',$networkId)->first();
        if (!$currency || !$currency->status || !$currency->withdraw_status) return 'Withdrawals are not allowed for this currency';
        if (!$network || !$network->status || !$network->withdraw_status) return 'Withdrawals are not allowed for this network';
        $disabled = $currency->disabled_withdrawal_networks ?? '';
        $decoded = is_string($disabled) ? json_decode($disabled, true) : $disabled;
        $disabled = is_array($decoded) ? $decoded : preg_split('/[,;\s]+/', trim((string)$disabled), -1, PREG_SPLIT_NO_EMPTY);
        if (in_array($networkId,array_map('intval',$disabled),true)) return 'Withdrawals are not allowed for this network';
        if ($internal && $network->slug === 'internal') return null;
        if (AssetNetworkOptions::nativeMismatch($currency, $network->slug)) return 'This network is not supported for the selected currency';
        if ($error=\App\Services\Market\AssetProfile::networkError($currency,$network->slug)) return $error;
        if (!DB::table('currency_networks')->where('currency_id',$currencyId)->where('network_id',$networkId)->exists()) return 'This network is not supported for the selected currency';
        $contracts = ['erc20'=>'contract','bep20'=>'bep_contract','trc20'=>'trc_contract','matic20'=>'matic_contract','xlayer20'=>'xlayer_contract','solspl'=>'sol_contract'];
        if (isset($contracts[$network->slug]) && !trim((string)($currency->{$contracts[$network->slug]} ?? ''))) return 'The token contract is not configured for this network';
        return null;
    }

    public function assertSupported(int $currencyId, int $networkId, bool $internal = false): void
    {
        if ($error=$this->error($currencyId,$networkId,$internal)) throw ValidationException::withMessages(['network'=>__($error)]);
    }
}
