<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeMinAmountRule implements Rule
{
    public $error = 'Invalid amount';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $amount)
    {
        if(!$amount) return false;

        if((float)$amount <= 0) return false;

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
