<?php

namespace App\Http\Requests\Api\Wallet\Rules;

use App\Models\Network\Network;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Network\NetworkRepository;
use Illuminate\Contracts\Validation\Rule;

class WalletWithdrawNetworkRule implements Rule
{
    /**
     * @var NetworkRepository
     */
    private $networkRepository, $error;

    public function __construct()
    {
        $this->networkRepository = new NetworkRepository();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  string $uuid
     * @return bool
     */
    public function passes($attribute, $networkId)
    {
        $currency = (new CurrencyRepository())->getCurrencyBySymbol(request()->get('symbol'));
        if (!$currency || filter_var($networkId, FILTER_VALIDATE_INT) === false) {
            $this->error = 'Invalid Network';
            return false;
        }
        $this->error = app(\App\Services\Wallet\WithdrawalNetworkPolicy::class)->error((int)$currency->id,(int)$networkId);
        return $this->error === null;
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
