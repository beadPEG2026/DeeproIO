<?php

namespace App\Http\Requests\Api\Wallet\Rules;

use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Contracts\Validation\Rule;

class WalletGetAddressRule implements Rule
{
    /**
     * @var CurrencyRepository
     */
    private $currencyRepository, $error;

    public function __construct()
    {
        $this->currencyRepository = new CurrencyRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $uuid
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        $currency = $this->currencyRepository->getCurrencyBySymbol($symbol);

        if(!$currency || $currency->type !== 'coin') {
            $this->error = 'Invalid Currency';
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
