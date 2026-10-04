<?php

namespace App\Http\Requests\Web\Staking;

use App\Http\Requests\Web\Staking\Rules\StakingAmountRule;
use App\Http\Requests\Web\Staking\Rules\StakingPurchasableRule;
use App\Http\Requests\Web\Staking\Rules\StakingRedeemableRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class StakingRedeemRequest extends FormRequest
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
            'id' => ['bail', 'integer', 'numeric', 'required', new StakingRedeemableRule()],
        ];
    }
}
