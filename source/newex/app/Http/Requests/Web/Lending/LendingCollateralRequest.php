<?php

namespace App\Http\Requests\Web\Lending;

use App\Http\Requests\Web\Lending\Rules\LendingCollateralAmountRule;
use App\Http\Requests\Web\Lending\Rules\LendingRepayAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class LendingCollateralRequest extends FormRequest
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
            'id' => ['bail', 'integer', 'numeric', 'required', 'exists:lending_users'],
            'amount' => ['bail', 'required', 'numeric', new LendingCollateralAmountRule()],
        ];
    }
}
