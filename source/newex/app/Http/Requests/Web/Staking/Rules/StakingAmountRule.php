<?php

namespace App\Http\Requests\Web\Staking\Rules;

use App\Models\Staking\Staking;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Staking\StakingWalletService;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StakingAmountRule implements Rule
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
        $staking = Staking::where('id', request()->get('id'))->active()->first();

        if(!$staking) {
            $this->error = 'Staking is not active';
            return false;
        }

        if (app(\App\Services\Staking\FundedTermProduct::class)->enabled($staking)) {
            $user=auth()->user();
            $wallet=$user?(new WalletRepository())->getWalletByCurrency($user->id,$staking->currency_id,false):null;
            if (!preg_match('/^\d+(?:\.\d{1,8})?$/D',(string)$amount) || strlen((string)$amount)>40) {
                $this->error='Use a positive decimal amount with at most 8 decimal places';return false;
            }
            if (!$user || $user->is_xn || !$wallet || bccomp((string)$amount,(string)$staking->min_amount,18)<0
                || bccomp((string)$amount,(string)$staking->max_amount,18)>0 || bccomp((string)$amount,(string)$wallet->balance_in_trade,18)>0) {
                $this->error='Invalid amount or insufficient spot balance';return false;
            }
            return true; // The locked subscription transaction rechecks the reserve and all limits.
        }

        if(math_compare($amount, $staking->min_amount) === -1) {
            $this->error = 'Amount is less than minimum stake amount';
            return false;
        }

        if(math_compare($amount, $staking->max_amount) === 1) {
            $this->error = 'Amount is more than maximum stake amount';
            return false;
        }

        $user = auth()->user();

        $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $staking->currency_id, false);

        if(!$wallet) {
            $this->error = 'Wallet not found';
            return false;
        }

        try {
            (new StakingWalletService())->getDebitSource($user, $wallet, $amount);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->error = $errors['amount'][0] ?? 'Insufficient balance';

            return false;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage() ?: 'Insufficient balance';

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
