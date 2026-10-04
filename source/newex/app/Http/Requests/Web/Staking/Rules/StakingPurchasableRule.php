<?php

namespace App\Http\Requests\Web\Staking\Rules;

use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use Illuminate\Contracts\Validation\Rule;

class StakingPurchasableRule implements Rule
{
    public $error = 'Staking is not active';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $staking = Staking::where('id', $value)->active()->first();

        $user = auth()->user();

        if(!$staking) return false;

        $stakingUser = StakingUser::where('user_id', $user->id)->where('staking_id', $staking->id)->active()->first();

        if($stakingUser) {
            $this->error = 'You already have been staked your coins in this pool';
            return false;
        }

        // Check days
        $days = explode(',', $staking->allowed_days);

        if(!in_array(request()->get('days'), $days)) {
            $this->error = 'Please select the stake period';
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
