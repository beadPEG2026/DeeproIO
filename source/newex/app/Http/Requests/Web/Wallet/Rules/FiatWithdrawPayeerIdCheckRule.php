<?php

namespace App\Http\Requests\Web\Wallet\Rules;

use App\Models\Deposit\FiatDeposit;
use App\Services\PaymentGateways\Fiat\Payeer\Services\Payeer;
use Illuminate\Contracts\Validation\Rule;

class FiatWithdrawPayeerIdCheckRule implements Rule
{
    public $errorMessage = 'Account holder was not found in Payeer';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $id)
    {
        $payeer = new Payeer();

        if($payeer->checkUser([
            'user' => $id,
        ])) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->errorMessage);
    }
}
