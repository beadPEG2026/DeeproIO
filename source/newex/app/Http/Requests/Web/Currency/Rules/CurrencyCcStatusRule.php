<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\BankAccount\BankAccount;
use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class CurrencyCcStatusRule implements Rule
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
        $cc_status = request()->get('cc_status', false);

        if(!in_array(NETWORK_BANK, $networks)) {
            return true;
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
