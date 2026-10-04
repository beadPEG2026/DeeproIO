<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Models\Order\Order;
use Illuminate\Contracts\Validation\Rule;

class OrderTriggerConditionRule implements Rule
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
        // Skip rule if order is not stop limit order
        if(!order_is_stop_limit(request()->get('type'))) return true;

        // Check available conditions
        return $value == Order::STOP_LIMIT_CONDITION_UP || $value == Order::STOP_LIMIT_CONDITION_DOWN;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('Invalid order price');
    }
}
