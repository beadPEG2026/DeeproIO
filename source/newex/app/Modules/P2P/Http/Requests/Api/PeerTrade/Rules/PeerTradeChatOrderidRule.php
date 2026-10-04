<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderMessage;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeChatOrderidRule implements Rule
{
    public $error = 'Order Id is not valid';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $id)
    {
        $user = auth()->user();

        if(!is_uuid_valid($id)) return false;

        $order = PeerOrder::query();

        $order->where(function ($query) use ($user) {
            $query->where('user_id', $user->id);
            $query->orWhere('ad_user_id', $user->id);
        });

        $order->where('id', $id);

        $model = $order->first();

        if(!$model) {
            return false;
        }


        $maxAmount = PeerOrderMessage::where('user_id', $user->id)->where('order_id', $id)->whereBetween('created_at', [now()->subMinutes(1), now()])->get()->count();

        if($maxAmount >= 20) {
            $this->error = "Spamming is detected";
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
