<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentPmCurrencies;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentPmCurrency;
use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use Illuminate\Contracts\Validation\Rule;

class PeerTradePaymentMethodRule implements Rule
{
    public $error = 'Minimum 1 and maximum 5 payment methods are required';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $ids)
    {
        $currency = Currency::type('fiat')->where('symbol', request()->get('fiat'))->active()->where('is_p2p', true)->first();
        $type = request()->get('side');
        $user = request()->user()->id;

        if(!$currency) return false;

        if(!is_array($ids)) return false;

        if(count($ids) == 0 || count($ids) > 5) return false;

        if($type == "buy") {

            foreach ($ids as $id) {
                if (!PeerPaymentMethod::active()->where('id', $id)->exists()) return false;

                if (!PeerPaymentPmCurrency::where('peer_pm_id', $id)->where('currency_id', $currency->id)->exists()) return false;
            }

        }

        if($type == "sell") {

            foreach ($ids as $id) {
                if (!PeerUserPaymentMethod::where('id', $id)->where('user_id', $user)->exists()) {
                    $this->error = "Invalid payment method";
                    return false;
                }
            }

        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->error);
    }
}
