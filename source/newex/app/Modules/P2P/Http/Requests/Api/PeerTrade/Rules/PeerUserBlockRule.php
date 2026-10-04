<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Models\PeerTrade\PeerUserBlacklist;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerUserBlockRule implements Rule
{
    public $error = 'Wrong user id';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $user)
    {
        $loggedUser = request()->user();
        $userModel = User::where('referral_code', $user)->first();

        if(!$userModel) return false;

        $blacklistModel = PeerUserBlacklist::where('user_id', $loggedUser->id)->where('blocked_user_id', $userModel->id)->first();

        if($blacklistModel) return false;

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
