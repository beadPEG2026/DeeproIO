<?php
namespace App\Services\Market;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use App\Services\Option\OptionPrice;
use Illuminate\Validation\ValidationException;
/** The existing product uses spot-reference prices, not an exchange perp index.
 * Real funds use an independently fetched timestamped reference, never editable display caches. */
class VerifiedDerivativePrice {
    public function forUser(Market $market, ?User $user): array {
        $virtual=(bool)($user?->is_xn || $user?->is_xm);
        if (!$virtual && $user) $virtual=\App\Models\Wallet\Wallet::where('user_id',$user->id)->where('currency_id',$market->quote_currency_id)->where('balance_in_virtual_trade','>',0)->exists();
        return $this->quote($market,$virtual);
    }
    public function forContract(FuturesContract $contract): array {
        $domain=$contract->referral_balance_domain;
        $virtual=in_array($domain,['real','virtual'],true) ? $domain==='virtual' : (bool)($contract->user?->is_xn || $contract->user?->is_xm);
        return $this->quote($contract->market,$virtual);
    }
    public function quote(Market $market, bool $virtual=false): array {
        if (!$virtual) return app(OptionPrice::class)->quote($market);
        $price=(string)market_get_stats($market->id,'last');
        if (!is_numeric($price) || bccomp($price,'0',18)<=0) throw ValidationException::withMessages(['price'=>__('A current price is unavailable.')]);
        return ['price'=>$price,'source'=>'simulation'];
    }
}
