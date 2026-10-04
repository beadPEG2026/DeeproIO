<?php

namespace App\Http\Requests\Web\Currency\Rules;

use App\Models\Currency\Currency;
use Illuminate\Contracts\Validation\Rule;

class CurrencyTrcContractRule implements Rule
{
    public $errorMessage = "";

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $contract)
    {
        $id = request()->get('id', false);
        $networks = request()->get('networks', false);

        if(!in_array(NETWORK_TRC, $networks)) {
            return true;
        }

        if(!$contract && in_array(NETWORK_TRC, $networks)) {
            $this->errorMessage = 'The TRC-20 Contract is required';
            return false;
        }

        if($contract && Currency::where('trc_contract', $contract)->where('id', '<>', $id)->first()) {
            $this->errorMessage = 'This Contract has already taken by other currency';
            return false;
        }

        if(mb_strlen($contract) <= 10) {
            $this->errorMessage = 'Invalid TRC-20 Contract Address';
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
        return $this->errorMessage;
    }
}
