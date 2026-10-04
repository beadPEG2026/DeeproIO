<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Http\Resources\PeerAd;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeUsernameRule implements Rule
{
    public $error = '';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $username)
    {
        $user = request()->user();

        if($user->peer_username_updated_at) {

            $diff = now()->diffInDays($user->peer_username_updated_at);

            if($diff < 180) {
                $this->error = 'You can modify once every 180 days. Last edit time was' . " " . $user->peer_username_updated_at;
                return false;
            }
        }

        if(!$username || trim($username) == "") return false;

        if (!preg_match('/^[A-Za-z_\-]+$/i', $username)) {
            $this->error = 'The username may contain only English letters, hyphen (-) and underscore (_) chars.';
            return false;
        }

        $model = User::where('peer_username', $username)->where('id', '!=', $user->id)->count();

        if($model) {
            $this->error = 'This username was already taken.';
            return false;
        }

        if(mb_strlen($username) > 20) {
            $this->error = 'The username must be between 1 and 20 chars.';
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
