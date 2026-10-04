<?php

namespace App\Http\Requests\Web\BankAccount\Rules;

use Illuminate\Contracts\Validation\Rule;

class SsnRule implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return mb_strlen($value) == 9 && is_numeric($value);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('Format of the SSN is wrong. e.g. 123457890');
    }
}

