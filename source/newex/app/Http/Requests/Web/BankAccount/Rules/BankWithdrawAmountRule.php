<?php

namespace App\Http\Requests\Web\BankAccount\Rules;

use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;

class BankWithdrawAmountRule implements Rule
{
    private $message;
    private $currencyRepository;
    private $walletRepository;
    private $extraError;

    public function __construct()
    {
        $this->currencyRepository = new CurrencyRepository();
        $this->walletRepository = new WalletRepository();
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
        $user = auth()->user();

        $currency = $this->currencyRepository->getCurrencyBySymbol('USD');
        $wallet = $this->walletRepository->getWalletByCurrency($user->id, $currency->id, false);

        if(math_compare($value, $currency->min_withdraw) < 0) {
            $this->extraError = ' ' . $currency->min_withdraw . ' ' . $currency->symbol;
            $this->message = 'Minimum withdrawal amount is';
            return false;
        }

        if($currency->max_withdraw > 0 && math_compare($value, $currency->max_withdraw) > 0) {
            $this->extraError = ' ' . $currency->max_withdraw . ' ' . $currency->symbol;
            $this->message = 'Maximum withdrawal amount is';
            return false;
        }

        if($value <= 0) {
            $this->message = "Invalid amount";
            return false;
        }

        if (!$wallet) {
            return false;
        }

        if($wallet->balance_in_wallet < $value) {
            $this->message = 'Insufficient balance';
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
        return __($this->message) . $this->extraError;
    }
}
