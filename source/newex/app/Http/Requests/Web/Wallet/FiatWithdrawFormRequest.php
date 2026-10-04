<?php

namespace App\Http\Requests\Web\Wallet;

use App\Http\Requests\Web\Wallet\Rules\FiatWithdrawAmountRule;
use App\Http\Requests\Web\Wallet\Rules\FiatWithdrawCurrencyRule;
use App\Services\Currency\CurrencyService;
use Illuminate\Foundation\Http\FormRequest;

class FiatWithdrawFormRequest extends FormRequest
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
        $currency = (new CurrencyService())->getCurrency(request()->get('currency_id'), false, false, ['networks']);

        $networks = $currency->networks->pluck('slug')->toArray();

        if(in_array(NETWORK_PERFECT_MONEY_SLUG, $networks)) {
            return [
                'currency_id' => ['bail','required', 'integer', 'numeric', new FiatWithdrawCurrencyRule()],
                'account_holder_address' => ['required', 'max:13', 'starts_with:U'],
                'amount' => ['bail','required', 'numeric', new FiatWithdrawAmountRule()],
            ];
        }

        return [
            'currency_id' => ['bail','required', 'integer', 'numeric', new FiatWithdrawCurrencyRule()],
            'name' => ['required', 'max:255'],
            'country_id' => ['required', 'integer', 'numeric', 'exists:countries,id'],
            'iban' => ['required', 'max:255'],
            'swift' => ['required', 'max:255'],
            'ifsc' => ['nullable', 'max:255'],
            'address' => ['required', 'max:255'],
            'account_holder_name' => ['required', 'max:255'],
            'account_holder_address' => ['required', 'max:255'],
            'amount' => ['bail','required', 'numeric', new FiatWithdrawAmountRule()],
        ];
    }
}
