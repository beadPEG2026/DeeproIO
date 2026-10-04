<?php

namespace App\Http\Requests\Web\Wallet;

use App\Http\Requests\Web\Wallet\Rules\FiatDepositAmountRule;
use App\Http\Requests\Web\Wallet\Rules\FiatDepositCurrencyRule;
use App\Models\Currency\Currency;
use Illuminate\Foundation\Http\FormRequest;

class FiatPayeerDepositFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $fiatCurrency = Currency::with('networks')->whereHas('networks', function($q){
            $q->where('network_id', NETWORK_PAYEER);
        })->first();

        if(!$fiatCurrency) {
            return [];
        }

        $validation['amount'] = ['required', 'numeric'];

        if($fiatCurrency->min_deposit) {
            $validation['amount'][] = 'min:'.$fiatCurrency->min_deposit;
        }

        if($fiatCurrency->max_deposit > 0 && $fiatCurrency->max_deposit) {
            $validation['amount'][] = 'max:'.$fiatCurrency->max_deposit;
        }

        return $validation;
    }

    public function attributes()
    {
        return [
            'currency_id' => __('Currency'),
            'reference' => __('Reference code')
        ];
    }
}
