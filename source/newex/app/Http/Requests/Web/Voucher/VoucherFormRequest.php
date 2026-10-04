<?php

namespace App\Http\Requests\Web\Voucher;

use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class VoucherFormRequest extends FormRequest
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
            'id' => ['sometimes', 'integer', 'numeric', 'required', 'exists:vouchers'],
            'code' => ['bail', 'required', 'min:1','max:20', Rule::unique('vouchers')->ignore(request()->get('id', false), 'id')],
            'amount' => ['bail', 'required', 'numeric'],
            'currency_id' => ['required', 'exists:currencies,id'],
            'is_redeemed' => ['required', 'boolean'],
        ];
    }
}
