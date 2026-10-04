<?php

namespace App\Http\Requests\Web\Launchpad\Rules;

use App\Repositories\Market\MarketRepository;
use Illuminate\Contracts\Validation\Rule;

class LaunchpadDecimalRule implements Rule
{
    public $error = 'Invalid amount';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if(!$value || !is_numeric($value) || (mb_strpos('e', (string)$value) !== false) || (mb_strpos('E', (string)$value) !== false) || math_compare($value, 0) < 1) {
            $this->error = "Invalid format of the amount";
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
