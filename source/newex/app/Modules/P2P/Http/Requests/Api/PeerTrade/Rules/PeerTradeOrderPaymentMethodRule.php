<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Http\Resources\UserPaymentMethod;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentPmCurrencies;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentPmCurrency;
use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeOrderPaymentMethodRule implements Rule
{
    public $error = 'Payment method is not valid';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $payment_id)
    {
        $ad = PeerAd::where('id', request()->get('ad_id'))->with('paymentMethods')->first();
        $user = request()->user();

        if(!$ad || !$payment_id) return false;

        $availableIds = [];

        foreach ($ad->paymentMethods as $method) {
            $availableIds[] = $method->id;
        }

        if($ad->type == "sell") {

            // If not valid payment id throw an exception
            if(!in_array($payment_id, $availableIds)) {
                return false;
            }

            $paymentMethod = PeerUserPaymentMethod::where('payment_method', $payment_id)->active()->where('user_id', $user->id)->first();

            if(!$paymentMethod) {
                $this->error = 'You do not have active account for this payment method';
                return false;
            }

        }

        if($ad->type == "buy") {

            $paymentMethod = PeerUserPaymentMethod::where('id', $payment_id)->active()->where('user_id', $user->id)->first();

            if(!$paymentMethod) return false;

            if(!in_array($paymentMethod->payment_method, $availableIds)) {
                return false;
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
