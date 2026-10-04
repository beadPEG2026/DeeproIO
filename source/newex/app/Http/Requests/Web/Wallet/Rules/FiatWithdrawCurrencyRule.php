<?php

namespace App\Http\Requests\Web\Wallet\Rules;

use App\Models\FileUpload\FileUpload;
use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Contracts\Validation\Rule;

class FiatWithdrawCurrencyRule implements Rule
{
    public $errorMessage = '';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $currency)
    {
        $currency = (new CurrencyRepository())->get($currency);

        if(!$currency || !$currency->withdraw_status || $currency->type !== 'fiat') {
            $this->errorMessage = 'Currency is not valid';
            return false;
        }

        if(!$currency->withdraw_status) {
            $this->errorMessage = 'Withdrawals suspended';
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
        return __($this->errorMessage);
    }
}
