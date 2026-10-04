<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\Network\Network;
use App\Services\PaymentGateways\Coin\Coinpayments\Model\CoinpaymentsCurrency;
use Illuminate\Contracts\Validation\Rule;

class CurrencyNetworkRule implements Rule
{
    public $errorMessage = "The Currency Network is invalid";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        $networks = request()->get('networks', false);

        if(!is_array($networks) || empty($networks)) return false;

        foreach ($networks as $network) {
            if(!Network::whereId($network)->first()) return false;
        }

        if(in_array(NETWORK_COINPAYMENTS, $networks)) {

            $alt_symbol = request()->get('alt_symbol', false);

            if(!$alt_symbol || !CoinpaymentsCurrency::whereSymbol($alt_symbol)->first()) {
                $this->errorMessage = 'Coinpayments Currency should be selected from the dropdown';
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
        return $this->errorMessage;
    }
}
