<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderAppeal;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeOrderAppealCancelRule implements Rule
{
    public $error = 'Wrong Appeal ID';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $id)
    {
        $appeal = PeerOrder::where('id', $id)->first();
        $user = request()->user();

        if(!$appeal) return false;

        if($appeal->status == "appealed_by_counterparty") {

            if($appeal->appealed_by !== $user->id) {
                return false;
            }

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
