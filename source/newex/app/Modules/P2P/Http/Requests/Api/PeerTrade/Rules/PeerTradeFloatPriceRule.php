<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeFloatPriceRule implements Rule
{
    public $error = 'Floating Price margin should be between [80% - 120%]';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $amount)
    {
        if(request()->get('type') == "fixed") return true;

        if(!$amount) return false;

        if((float)$amount <= 0) return false;

        if($amount < 80 || $amount > 120) {
            return false;
        }

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
