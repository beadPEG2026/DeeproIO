<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Models\Order\Order;
use Illuminate\Contracts\Validation\Rule;

class OptionTypeRule implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $type
     * @return bool
     */
    public function passes($attribute, $type)
    {
        return $type == 1
        || $type == 2
        || $type == 3
        || $type == 4
        || $type == 5;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('Invalid option type');
    }
}
