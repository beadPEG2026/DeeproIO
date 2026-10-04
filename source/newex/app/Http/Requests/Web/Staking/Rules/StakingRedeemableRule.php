<?php

namespace App\Http\Requests\Web\Staking\Rules;

use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use Illuminate\Contracts\Validation\Rule;

class StakingRedeemableRule implements Rule
{
    public $error = 'Staked coins are not redeemable';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $user = auth()->user();

        $staking = StakingUser::where('id', $value)->where('user_id', $user->id)->active()->first();

        if(!$staking) return false;

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
