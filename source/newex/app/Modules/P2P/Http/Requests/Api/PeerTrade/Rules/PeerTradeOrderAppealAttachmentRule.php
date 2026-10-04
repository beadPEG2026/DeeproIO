<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules;

use App\Models\Currency\Currency;
use App\Models\FileUpload\FileUpload;
use App\Models\Wallet\Wallet;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeerTradeOrderAppealAttachmentRule implements Rule
{
    public $error = '';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $ids)
    {
        foreach ($ids as $id) {

            if(!FileUpload::where('id', $id)->first()) {
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
