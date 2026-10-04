<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeFiatRule implements Rule
{
    public $error = 'Invalid fiat asset';

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

        $currency = Currency::type('fiat')->where('symbol', $symbol)->active()->where('is_p2p', true)->first();
        $baseCurrency = Currency::type('coin')->where('symbol', request()->get('coin'))->active()->where('is_p2p', true)->first();

        if(!$currency || !$baseCurrency) return false;

        //$user = request()->user();

        //$peerAd = PeerAd::active()->where('type', request()->get('side'))->where('user_id', $user->id)->where('base_currency_id', $baseCurrency->id)->where('quote_currency_id', $currency->id)->count();

        //if($peerAd) {
            //$this->error = 'You are allowed to have only 1 active ad for this coin pair';
            //return false;
        //}

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
