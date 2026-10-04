<?php

namespace App\Http\Requests\Api\Wallet\Gateways\Stripe;

use App\Http\Requests\Api\Wallet\Rules\WalletDepositStripeAmountRule;
use App\Http\Requests\Api\Wallet\Rules\WalletDepositStripeCurrencyRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class StripePaymentFormRequest extends FormRequest
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
        return [
            'currency_id' => ['bail', 'required', new WalletDepositStripeCurrencyRule()],
            'amount' => ['bail', 'required', 'numeric', new WalletDepositStripeAmountRule()],
        ];
    }
}
