<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\Market\Market;
use Illuminate\Contracts\Validation\Rule;

class CurrencyStatusRule implements Rule
{
    public $error = "You can not hide currency existing in markets";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $status)
    {
        // Variables
        if(!isset(request()->currency)) return true;

        // If currency exists in markets prevent hidden status
        if(!$status) {
            return !Market::where('base_currency_id', request()->currency->id)->orWhere('quote_currency_id', request()->currency->id)->exists();
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
        return $this->error;
    }
}
