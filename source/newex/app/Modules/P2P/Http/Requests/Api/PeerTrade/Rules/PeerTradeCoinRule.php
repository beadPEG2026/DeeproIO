<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Http\Resources\PeerAd;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeCoinRule implements Rule
{
    public $error = 'Invalid coin asset';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        if(!$symbol) return false;

        $currency = Currency::type('coin')->where('symbol', $symbol)->active()->where('is_p2p', true)->first();

        if(!$currency) return false;

        $user = request()->user();

        if($user->p2p_trade_ban) {
            $this->error = "P2P trade was blocked for you";
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
