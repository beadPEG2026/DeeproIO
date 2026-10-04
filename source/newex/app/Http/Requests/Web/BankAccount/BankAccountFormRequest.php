<?php

namespace App\Http\Requests\Web\BankAccount;

use App\Http\Requests\Web\Currency\Rules\CurrencyNetworkRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencySymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTypeRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class BankAccountFormRequest extends FormRequest
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
            'id' => ['sometimes', 'integer', 'numeric', 'required', 'exists:bank_accounts'],
            'name' => ['required', 'max:255'],
            'reference_number' => ['sometimes','max:255'],
            'iban' => ['sometimes', 'max:255'],
            'swift' => ['sometimes', 'max:255'],
            'ifsc' => ['sometimes', 'max:255'],
            'address' => ['sometimes', 'max:255'],
            'account_holder_name' => ['sometimes', 'max:255'],
            'account_holder_address' => ['sometimes', 'max:255'],
            'note' => ['sometimes', 'max:2000'],
            'status' => ['required', 'boolean'],
            'country_id' => ['required', 'integer', 'numeric', 'exists:countries,id'],
        ];
    }
}
