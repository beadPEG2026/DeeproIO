<?php

namespace App\Http\Requests\Api\Wallet\Rules;

use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Contracts\Validation\Rule;
use PragmaRX\Google2FA\Google2FA;

class WalletSymbolRule implements Rule
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

        if(!$currency->withdraw_status) {
            $this->error = 'Withdrawal operation was disabled for this currency';
            return false;
        }

        $user = request()->user();

        if($user->two_factor_secret) {
            $google2fa = app(Google2FA::class);

            $valid = $google2fa->verifyKey(decrypt($user->two_factor_secret), (string)request()->get('twofa'), 1);

            if (!$valid) {
                $this->error = '2FA Code is invalid or expired.';
                return false;
            }
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
