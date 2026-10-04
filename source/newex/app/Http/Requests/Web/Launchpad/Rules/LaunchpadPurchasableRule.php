<?php

namespace App\Http\Requests\Web\Launchpad\Rules;

use App\Models\Launchpad\Launchpad;
use Illuminate\Contracts\Validation\Rule;

class LaunchpadPurchasableRule implements Rule
{
    public $error = 'Launchpad is not active';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $launchpad = Launchpad::published()->where('id', $value)->where('status', 1)->where('purchasable', 1)->first();

        if(!$launchpad) return false;

        return true;
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
