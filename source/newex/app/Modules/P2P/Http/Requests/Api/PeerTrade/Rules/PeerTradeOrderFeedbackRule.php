<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeOrderFeedbackRule implements Rule
{
    public $error = 'Invalid order id';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $id)
    {
        if(!is_uuid_valid($id)) return false;

        $user = request()->user();

        if(!$user) return false;

        $order = PeerOrder::where('id', $id)->where('user_id', $user->id)->first();

        if(!$order || $order->status !== "completed") return false;

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
