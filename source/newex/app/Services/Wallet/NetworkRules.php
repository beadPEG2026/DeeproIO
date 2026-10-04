<?php
namespace App\Services\Wallet;

use App\Models\Deposit\DepositChannel;

final class NetworkRules
{
    public static function deposit($currency,int $network,?DepositChannel $channel): array {
        $suffix=[3=>'_erc',6=>'_bep',8=>'_trc',16=>'_matic',21=>'_sol',25=>'_xlayer'][$network]??'';
        return [
            'minimum'=>(string)($channel?->isPilot()?$channel->pilot_minimum:($channel?->minimum??$currency->min_deposit)),
            'pilot'=>(bool)$channel?->isPilot(),
            'pilot_limit'=>$channel?->isPilot()?(string)$channel->pilot_limit:null,
            'pilot_expires_at'=>$channel?->isPilot()?$channel->pilot_expires_at?->toIso8601String():null,
            'fee_fixed'=>(string)($channel?->fee_fixed??$currency->{'deposit_fee'.$suffix.'_fixed'}??0),
            'fee_percent'=>(string)($channel?->fee_percent??$currency->{'deposit_fee'.$suffix}??0),
            'confirmations'=>(int)($channel?->confirmations??$currency->min_deposit_confirmation),
        ];
    }
    public static function withdrawal($currency,int $network): array {
        $suffix=[3=>'_erc',6=>'_bep',8=>'_trc',16=>'_matic',21=>'_sol',25=>'_xlayer'][$network]??'';
        return ['minimum'=>(string)($currency->min_withdraw??0),'fee_fixed'=>(string)($currency->{'withdraw_fee'.$suffix.'_fixed'}??0),'fee_percent'=>(string)($currency->{'withdraw_fee'.$suffix}??0)];
    }
}
