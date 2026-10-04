<?php

namespace App\Http\Requests\Api\Wallet\Rules;

use App\Models\Network\Network;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Network\NetworkRepository;
use Illuminate\Contracts\Validation\Rule;

class WalletNetworkRule implements Rule
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
        if(!$this->networkRepository->getById($networkId)) {
            $this->error = 'Invalid Network';
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
