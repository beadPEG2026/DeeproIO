<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerUserBlacklist;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeOrderStatusRule implements Rule
{
    public $error = 'Invalid ad';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $ad_id)
    {
        if(!$ad_id || !is_uuid_valid($ad_id)) return false;

        $user = request()->user();

        $ad = PeerAd::where('id', $ad_id)->where('user_id', '!=', $user->id)->active()->first();

        if(!$ad) return false;

        $blackListed = PeerUserBlacklist::where('blocked_user_id', $ad->user_id)->where('user_id', $user->id)->count();

        if($blackListed) {
            $this->error = 'This user was blocked by you, therefore you can not create an order';
            return false;
        }

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
