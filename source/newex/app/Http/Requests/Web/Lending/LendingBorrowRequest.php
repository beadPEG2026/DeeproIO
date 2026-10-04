<?php

namespace App\Http\Requests\Web\Lending;

use App\Http\Requests\Web\Lending\Rules\LendingAmountRule;
use App\Http\Requests\Web\Lending\Rules\LendingPurchasableRule;
use App\Http\Requests\Web\Staking\Rules\StakingAmountRule;
use App\Http\Requests\Web\Staking\Rules\StakingPurchasableRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class LendingBorrowRequest extends FormRequest
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
            'id' => ['bail', 'integer', 'numeric', 'required', 'exists:lending', new LendingPurchasableRule()],
            'amount' => ['bail', 'required', 'numeric', new LendingAmountRule()],
            'type' => ['required', 'in:flexible,weekly,monthly']
        ];
    }
}
