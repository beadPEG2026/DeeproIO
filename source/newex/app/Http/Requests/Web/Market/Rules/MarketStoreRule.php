<?php

namespace App\Http\Requests\Web\Market\Rules;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use Illuminate\Contracts\Validation\Rule;

class MarketStoreRule implements Rule
{
    public $errorMessage = "You can not pair same currencies";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $name)
    {
        $base_currency_id = request()->get('base_currency_id', false);
        $quote_currency_id = request()->get('quote_currency_id', false);

        if($base_currency_id == $quote_currency_id) return false;

        if(!Currency::where('id', intval($base_currency_id))->first()) {
            return false;
        }

        if(!Currency::where('id', intval($quote_currency_id))->first()) {
            return false;
        }

        // Check if market already exists e.g. BTC-USDT
        $market_id = intval(request()->get('id', false));

        if(Market::where('base_currency_id', $base_currency_id)->where('quote_currency_id', $quote_currency_id)->where('id', '<>', $market_id)->exists()) {
            $this->errorMessage = 'The Market already exists';
            return false;
        }

        // Check if market already exists e.g. USDT-BTC
        if(Market::where('quote_currency_id', $base_currency_id)->where('base_currency_id', $quote_currency_id)->where('id', '<>', $market_id)->exists()) {
            $this->errorMessage = 'The Market already exists';
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
