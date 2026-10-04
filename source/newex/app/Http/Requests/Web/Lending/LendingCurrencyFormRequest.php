<?php

namespace App\Http\Requests\Web\Lending;

use Illuminate\Foundation\Http\FormRequest;
use Auth;

class LendingCurrencyFormRequest extends FormRequest
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
            'id' => ['sometimes', 'required', 'numeric', 'integer','exists:lending_currencies'],
            'currency_id' => ['bail', 'required'],

            'flex_initial_ltv' => ['required', 'numeric'],
            'flex_margin_call' => ['required', 'numeric'],
            'flex_liquidation_ltv' => ['required', 'numeric'],

            'weekly_initial_ltv' => ['required', 'numeric'],
            'weekly_margin_call' => ['required', 'numeric'],
            'weekly_liquidation_ltv' => ['required', 'numeric'],

            'monthly_initial_ltv' => ['required', 'numeric'],
            'monthly_margin_call' => ['required', 'numeric'],
            'monthly_liquidation_ltv' => ['required', 'numeric'],
        ];
    }
}
