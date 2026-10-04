<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Repositories\Market\MarketRepository;
use Illuminate\Contracts\Validation\Rule;

class OrderPriceRule implements Rule
{
    public $error = 'Invalid order price';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        // Request variables
        $market = request()->get('market');
        $type = request()->get('type');

        // Skip rule if order is market buy order
        if(order_is_market($type)) return true;

        if(!$value || !is_numeric($value) || (mb_strpos((string)$value, 'e') !== false) || (mb_strpos((string)$value, 'E') !== false) || math_compare($value, 0) < 1) {
            $this->error = "Invalid format of the price";
            return false;
        }

        // Get market by name
        $market = (new MarketRepository())->get($market);

        if (!$market) { $this->error = 'Invalid market name'; return false; }

        // Validate price precision matches quote_precision
        if(!math_decimal_validation($value, $market->quote_precision)) {
            $this->error = "Price exceeds maximum allowed decimal places: " . $market->quote_precision;
            return false;
        }

        return $value && $value > 0;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->error);
    }
}
