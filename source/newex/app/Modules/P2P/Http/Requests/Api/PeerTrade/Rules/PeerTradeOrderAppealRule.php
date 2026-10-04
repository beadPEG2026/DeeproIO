<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeOrderAppealRule implements Rule
{
    public $error = 'Appeal is not available';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $amount)
    {
        $repository = new PeerOrderRepository();
        $user = auth()->user();
        $id = request()->get('id');

        $order = $repository->getPeerOrderById($id);

        if(!$order) {
            $this->error = 'Wrong Order Id';
            return false;
        }

        if($order->ad_user_id !== $user->id  && $order->user_id !== $user->id) {
            $this->error = 'Wrong Order Id';
            return false;
        }

        if($order->status !== "confirm_transfer") return false;


        if($order->type == "sell" && $order->user_id == $user->id) {
            return true;
        }

        if($order->type == "buy" && $order->ad_user_id == $user->id) {
            return true;
        }

        $appealAvailableAfter = now()->diffInSeconds($order->paid_at->addMinutes(10), false);
        $appealAvailable = $appealAvailableAfter <= 0;

        if(!$appealAvailable) return false;

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
