<?php

namespace App\Http\Requests\Web\Wallet;

use App\Http\Requests\Web\Wallet\Rules\FiatDepositAmountRule;
use App\Http\Requests\Web\Wallet\Rules\FiatDepositCurrencyRule;
use Illuminate\Foundation\Http\FormRequest;

class FiatDepositFormRequest extends FormRequest
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
            'currency_id' => ['bail', 'required', 'numeric', new FiatDepositCurrencyRule()],
            'amount' => ['bail', 'required', 'numeric', 'max:999999999999999', new FiatDepositAmountRule()],
            'receipt_id' => ['bail', 'required', 'numeric', 'exists:file_uploads,id'],
            'note' => ['bail', 'max:400'],
        ];
    }

    public function attributes()
    {
        return [
            'receipt_id' => __('Receipt')
        ];
    }
}
