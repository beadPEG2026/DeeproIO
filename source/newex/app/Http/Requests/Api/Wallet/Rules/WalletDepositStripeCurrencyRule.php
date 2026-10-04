<?php

namespace App\Http\Requests\Api\Wallet\Rules;

use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;

class WalletDepositStripeCurrencyRule implements Rule
{
    private $message;
    private $currencyRepository;

    public function __construct()
    {
        $this->currencyRepository = new CurrencyRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $currency = $this->currencyRepository->get(request()->get('currency_id'));

        if(!$currency || $currency->type !== 'fiat' || !$currency->deposit_status) {
            $this->message = "Invalid currency or deposits are not allowed";
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
        return __($this->message);
    }
}
