<?php

namespace App\Http\Requests\Web\Currency\Rules;

use Illuminate\Contracts\Validation\Rule;

class CurrencyTypeRule implements Rule
{
    public $errorMessage = "Coin type is invalid";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $type)
    {
        return $type == "coin" || $type == "fiat";
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
