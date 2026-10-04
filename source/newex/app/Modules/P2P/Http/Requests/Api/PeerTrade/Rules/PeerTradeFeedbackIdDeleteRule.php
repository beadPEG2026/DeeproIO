<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use Illuminate\Contracts\Validation\Rule;

class PeerTradeFeedbackIdDeleteRule implements Rule
{
    public $error = 'Feedback not found';

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

        $repository = new PeerAdRepository();
        $user = auth()->user();

        $ad = $repository->getPeerFeedbackById($id, $user->id);

        if(!$ad) {
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
