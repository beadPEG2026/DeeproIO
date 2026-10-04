<?php

namespace App\Http\Requests\Web\Lending\Rules;

use App\Models\Lending\Lending;
use App\Models\Lending\LendingUser;
use App\Models\Staking\Staking;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;

class LendingCollateralAmountRule implements Rule
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
        $user = auth()->user();

        $lending = LendingUser::where('id', request()->get('id'))->where('user_id', $user->id)->active()->first();

        if(!$lending) {
            $this->error = 'Lending is not active or not found';
            return false;
        }

        if($amount <= 0) {
            $this->error = 'Amount can not be zero or less';
            return false;
        }

        $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $lending->collateral_id, false);

        if(math_compare($wallet->balance_in_wallet, $amount) === -1) {
            $this->error = 'Insufficient balance';
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
