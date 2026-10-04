<?php

namespace App\Http\Requests\Web\Lending\Rules;

use App\Models\Lending\Lending;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use App\Repositories\Lending\LendingRepository;

class LendingAmountRule implements Rule
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
        $lending = Lending::where('id', request()->get('id'))->active()->first();

        if(!$lending) {
            $this->error = 'Lending is not active';
            return false;
        }

        if(math_compare($amount, $lending->min_amount) === -1) {
            $this->error = 'Amount is less than minimum borrow amount';
            return false;
        }

        if(math_compare($amount, $lending->max_amount) === 1) {
            $this->error = 'Amount is more than maximum borrow amount';
            return false;
        }

        $user = auth()->user();
        $type = request()->get('type', 'flexible');

        $collateralCurrency = (new LendingRepository())->getCollateralByCurrency($lending->id, request()->get('collateral_id'));

        $collateralRequired = (new LendingRepository())->getCollateralRequiredAmount(
            $type,
            $amount,
            $lending,
            $collateralCurrency,
        );

        $wallet = (new WalletRepository())->getWalletByCurrency($user->id, request()->get('collateral_id'), false);

        if(math_compare($wallet->balance_in_wallet, $collateralRequired['amount']) === -1) {
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
