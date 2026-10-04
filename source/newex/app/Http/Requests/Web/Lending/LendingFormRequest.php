<?php

namespace App\Http\Requests\Web\Lending;

use Illuminate\Foundation\Http\FormRequest;
use Auth;

class LendingFormRequest extends FormRequest
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
            'id' => ['sometimes', 'required', 'numeric', 'integer','exists:lending'],
            'currency_id' => ['required', 'exists:currencies,id', 'unique:lending,currency_id,' . (request()->get('id') ?? 0)],
            'min_amount' => ['required', 'numeric', 'gte:0'],
            'max_amount' => ['required', 'numeric', 'gte:0'],
            'status' => ['required', 'max:20'],
        ];
    }
}
