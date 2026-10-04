<?php

namespace App\Http\Requests\Web\Currency\Rules;

use Illuminate\Contracts\Validation\Rule;

class CurrencyCcExchangeRateRule implements Rule
{
    public $errorMessage = "";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $cc_exchange_rate)
    {
        $networks = request()->get('networks', false);
        $cc_status = request()->get('cc_status', false);

        if(!in_array(NETWORK_BANK, $networks)) {
            return true;
        }

        if($cc_exchange_rate > 0 && $cc_exchange_rate < 0.001) {
            $this->errorMessage = 'Exchange rate should be at least 0.001';
            return false;
        }

        if($cc_status && !is_numeric($cc_exchange_rate)) {
            $this->errorMessage = 'Exchange Rate must be numeric value';
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
