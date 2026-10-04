<?php

namespace App\Http\Requests\Web\ColdStorage\Rules;

use App\Models\ColdStorage\ColdStorage;
use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use Illuminate\Contracts\Validation\Rule;

class ColdStorageCreateRule implements Rule
{
    public $error = 'Cold storage already added for this coin and network';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $currency_id = request()->get('currency_id');
        $network_id = request()->get('network_id');
        $id = request()->get('id');

        $coldStorage = ColdStorage::where('network_id', $network_id)->where('currency_id', $currency_id)->where('id', '!=', $id)->first();

        if($coldStorage) {
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
