<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeDeleteAdIdRule implements Rule
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

        $hasActiveOrder = $repository->hasActiveOrder($id);

        if($hasActiveOrder) {

            $this->error = 'Your have an active order therefore this ad can not be modified or closed';

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
