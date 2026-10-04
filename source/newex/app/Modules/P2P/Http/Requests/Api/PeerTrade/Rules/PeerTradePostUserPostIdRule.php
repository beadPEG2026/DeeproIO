<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use Illuminate\Contracts\Validation\Rule;

class PeerTradePostUserPostIdRule implements Rule
{
    public $error = 'This payment method is not editable';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $method)
    {
        $model = PeerUserPaymentMethod::where('id', $method)->where('user_id', request()->user()->id)->first();

        if(!$model) return false;

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
