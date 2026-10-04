<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Services\PaymentGateways\Coin\Coinpayments\Model\CoinpaymentsCurrency;
use Illuminate\Contracts\Validation\Rule;

class CurrencyAltSymbolRule implements Rule
{
    public $errorMessage = "";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        return true;

        $id = request()->get('id', false);
        $networks = request()->get('networks', false);

        $network = Network::whereSlug('coinpayments')->first();

        if(!$symbol && in_array($network->id, $networks)) {
            $this->errorMessage = 'Coinpayments Currency is required';
            return false;
        }

        if($symbol && Currency::where('alt_symbol', $symbol)->where('id', '<>', $id)->first()) {
            $this->errorMessage = 'This currency has already taken by other currency';
            return false;
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
