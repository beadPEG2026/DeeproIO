<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class CurrencySymbolRule implements Rule
{
    public $errorMessage = "The Currency already exist";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        // Check if currency already exists e.g. BTC
        $currency = request()->get('id', false);
        $networks = request()->get('networks', false);

        if(Currency::whereSymbol($symbol)->where('id', '<>', $currency)->exists()) {
            return false;
        }

        if(Currency::whereSymbol($symbol)->where('id', '<>', $currency)->withTrashed()->exists()) {
            $this->errorMessage = 'The Currency already exist in Trashed section. You should either restore on Trashed section it or rename its symbol.';
            return false;
        }

        if(in_array(NETWORK_ETH, $networks) && $symbol != "ETH") {
            $this->errorMessage = 'The symbol must be ETH if you selected ETH API';
            return false;
        }

        if(in_array(NETWORK_BNB, $networks) && $symbol != "BNB") {
            $this->errorMessage = 'The symbol must be BNB if you selected BNB API';
            return false;
        }

        if(in_array(NETWORK_TRX, $networks) && $symbol != "TRX") {
            $this->errorMessage = 'The symbol must be TRX if you selected TRX API';
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
