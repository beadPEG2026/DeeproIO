<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use Illuminate\Contracts\Validation\Rule;

class PeerTradePostUserPaymentIdRule implements Rule
{
    public $error = 'This payment method is not active';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $method)
    {
        $model = PeerPaymentMethod::where('id', $method)->active()->first();

        if(!$model) return false;

        if(!request()->get('post_id')) {

            $model = PeerUserPaymentMethod::where('user_id', request()->user()->id)->count();

            if ($model >= 20) {
                $this->error = 'You can add maximum 20 payment methods.';
                return false;
            }

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
