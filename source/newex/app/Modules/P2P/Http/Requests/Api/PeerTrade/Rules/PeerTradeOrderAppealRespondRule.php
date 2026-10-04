<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeOrderAppealRespondRule implements Rule
{
    public $error = '';

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

        if($order->status !== "appealed_by_counterparty") {
            $this->error = 'Appeal is not active';
            return false;
        }

        if($order->appealed_by !== $user->id && $order->appeal_stage !== "pending") {
            $this->error = 'It is not your turn to reply to this appeal';
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
