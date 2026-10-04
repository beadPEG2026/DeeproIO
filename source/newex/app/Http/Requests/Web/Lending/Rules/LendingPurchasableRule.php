<?php

namespace App\Http\Requests\Web\Lending\Rules;

use App\Models\Lending\Lending;
use App\Models\Lending\LendingUser;
use Illuminate\Contracts\Validation\Rule;

class LendingPurchasableRule implements Rule
{
    public $error = 'Loanable asset is not active';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $lending = Lending::where('id', $value)->active()->first();

        $user = auth()->user();

        if(!$lending) return false;

        $allowedBorrowTypes = [];

        if($lending->is_flexible) {
            $allowedBorrowTypes[] = 'flexible';
        }

        if($lending->is_monthly) {
            $allowedBorrowTypes[] = 'monthly';
        }

        if($lending->is_weekly) {
            $allowedBorrowTypes[] = 'weekly';
        }

        if(!in_array(request()->get('type'), $allowedBorrowTypes)) {
            $this->error = 'This type of borrow is not allowed for this asset';
            return false;
        }

        $lendingUser = LendingUser::where('user_id', $user->id)->where('lending_id', $lending->id)->active()->first();

        if($lendingUser) {
            $this->error = 'You have already borrowed from this asset';
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
