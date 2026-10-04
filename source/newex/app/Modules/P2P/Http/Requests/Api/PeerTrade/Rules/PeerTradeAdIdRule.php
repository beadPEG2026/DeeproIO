<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeAdIdRule implements Rule
{
    public $error = 'Ad not found';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $id)
    {
        $repository = new PeerAdRepository();
        $user = auth()->user();

        $ad = $repository->getPeerAdById($id, false, ['paymentMethods'], $user->id);

        if(!$ad) {
            return false;
        }

        if($user->p2p_trade_ban) {
            $this->error = "P2P trade was blocked for you";
            return false;
        }

        //if(request()->get('status') == "active") {

        //    $peerAd = PeerAd::active()->where('id', '!=', $ad->id)->where('type', $ad->type)->where('user_id', $user->id)->where('base_currency_id', $ad->base_currency_id)->where('quote_currency_id', $ad->quote_currency_id)->count();

        //    if ($peerAd) {
        //        $this->error = 'You are allowed to have only 1 active ad for this coin pair';
        //        return false;
        //    }

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
