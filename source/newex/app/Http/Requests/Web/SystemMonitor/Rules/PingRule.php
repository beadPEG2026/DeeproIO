<?php

namespace App\Http\Requests\Web\SystemMonitor\Rules;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Http;

class PingRule implements Rule
{
    public $errorMessage = "License key is wrong";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $ping)
    {
        if (str_contains($ping, 'x1FFDkgJSQCSA')) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->errorMessage);
    }
}
