<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\BankAccount\BankAccount;
use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class CurrencyBankStatusRule implements Rule
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
        $networks = request()->get('networks', false);
        $bank_status = request()->get('bank_status', false);
        $cc_status = request()->get('cc_status', false);

        if(!in_array(NETWORK_BANK, $networks)) {
            return true;
        }

        if(!$cc_status && !$bank_status) {
            $this->errorMessage = "Bank Transfer or Credit Card payment should be enabled for this network";
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
