<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\BankAccount\BankAccount;
use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class CurrencyBankAccountRule implements Rule
{
    public $errorMessage = "";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        $account = intval(request()->get('bank_account', false));
        $networks = request()->get('networks', false);

        if(!in_array(NETWORK_BANK, $networks)) {
            return true;
        }

        if(!request()->get('bank_status', false)) return true;

        if(!BankAccount::whereId($account)->active()->exists()) {
            $this->errorMessage = 'Bank Account does not exist';
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
        return $this->errorMessage;
    }
}
