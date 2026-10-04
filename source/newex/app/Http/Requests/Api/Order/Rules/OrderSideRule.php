<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Models\Order\Order;
use Illuminate\Contracts\Validation\Rule;

class OrderSideRule implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return $value == Order::SIDE_BUY || $value == Order::SIDE_SELL;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('Invalid order side');
    }
}
