<?php

namespace App\Http\Requests\Api\Wallet\Rules;

use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class WalletWithdrawAmountRule implements Rule
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

        $currency = $this->currencyRepository->getCurrencyBySymbol(request()->get('symbol'));
        $wallet = $this->walletRepository->getWalletByCurrency($user->id, $currency->id, false);

        $limit = $this->currencyRepository->getDailyAvailableWithdrawal($currency, $user);

        if($limit['status'] && ($limit['available'] <= 0)) {
            $this->message = 'You have reached your daily withdrawal limit.';
            return false;
        } elseif($limit['status'] && math_sub($limit['available'], $value) < 0) {
            $this->message = 'You have reached your daily withdrawal limit. Maximum available withdrawal amount is ' . $limit['available'] . $currency->symbol;
            return false;
        }

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

        if(math_compare($this->getAvailableWithdrawBalance($wallet, $user), $value) < 0) {
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

    protected function getAvailableWithdrawBalance($wallet, $user): string
    {
        $realBalance = $this->getWalletBalance($wallet, 'balance_in_wallet');

        if (!$this->isVirtualUser($user)) {
            return $realBalance;
        }

        $virtualBalance = $this->walletColumnExists('balance_in_virtual_wallet')
            ? $this->getWalletBalance($wallet, 'balance_in_virtual_wallet')
            : '0';

        return math_sum($realBalance, $virtualBalance);
    }

    protected function getWalletBalance($wallet, string $column): string
    {
        if (!$wallet) {
            return '0';
        }

        $value = null;

        if (method_exists($wallet, 'getRawOriginal')) {
            $value = $wallet->getRawOriginal($column);
        }

        if ($value === null) {
            $value = $wallet->{$column} ?? 0;
        }

        return $this->normalizeDecimal($value);
    }

    protected function isVirtualUser($user): bool
    {
        return filter_var($user->is_xn ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($user->is_xm ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function walletColumnExists(string $column): bool
    {
        try {
            return Schema::hasColumn('wallets', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function normalizeDecimal($value): string
    {
        $value = str_replace(',', '', (string) ($value ?? 0));

        return is_numeric($value) ? $value : '0';
    }
}
