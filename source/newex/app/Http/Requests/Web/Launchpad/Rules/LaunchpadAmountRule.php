<?php

namespace App\Http\Requests\Web\Launchpad\Rules;

use App\Models\Launchpad\Launchpad;
use App\Models\Launchpad\LaunchpadTransaction;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;

class LaunchpadAmountRule implements Rule
{
    public $error = '';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $amount)
    {
        $launchpad = Launchpad::published()->where('id', request()->get('id'))->where('status', 1)->where('purchasable', 1)->first();

        if(!$launchpad) {
            $this->error = 'Launchpad is not active';
            return false;
        }

        if(math_compare($amount, $launchpad->min_buy) < 0) {
            $this->error = 'Amount is less than minimum buy amount';
            return false;
        }

        // Check if this single purchase exceeds max_buy
        if(math_compare($amount, $launchpad->max_buy) > 0) {
            $this->error = 'Amount is more than maximum buy amount';
            return false;
        }

        $user = auth()->user();

        // Check cumulative purchases - user's total including this purchase should not exceed max_buy
        $userTotalPurchased = LaunchpadTransaction::where('launchpad_id', $launchpad->id)
            ->where('user_id', $user->id)
            ->sum('amount');
        
        $newTotal = math_sum($userTotalPurchased ?? '0', $amount);
        
        if(math_compare($newTotal, $launchpad->max_buy) > 0) {
            $remaining = math_sub($launchpad->max_buy, $userTotalPurchased ?? '0');
            if (math_compare($remaining, '0') <= 0) {
                $this->error = 'You have already reached the maximum buy limit for this launchpad';
            } else {
                $this->error = "Purchase would exceed your maximum limit. You can still buy up to {$remaining}";
            }
            return false;
        }

        // Check if purchase would exceed hard cap
        $newRaisedAmount = math_sum($launchpad->raised_amount, $amount);
        if (math_compare($newRaisedAmount, $launchpad->hard_cap) > 0) {
            $remaining = math_sub($launchpad->hard_cap, $launchpad->raised_amount);
            if (math_compare($remaining, '0') <= 0) {
                $this->error = 'Launchpad has reached its hard cap';
            } else {
                $this->error = "Purchase would exceed hard cap. Maximum available: {$remaining}";
            }
            return false;
        }

        $symbol = $launchpad->network_id == NETWORK_BNB ? 'BNB' : 'ETH';

        $currency = (new CurrencyRepository())->getCurrencyBySymbol($symbol);
        
        if (!$currency) {
            $this->error = 'Payment currency not available';
            return false;
        }

        $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $currency->id, false);

        if(!$wallet || math_compare($wallet->balance_in_wallet, $amount) < 0) {
            $this->error = 'Insufficient balance';
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
