<?php

namespace App\Http\Requests\Web\Launchpad;

use App\Http\Requests\Web\Launchpad\Rules\LaunchpadAmountRule;
use App\Http\Requests\Web\Launchpad\Rules\LaunchpadDecimalRule;
use App\Http\Requests\Web\Launchpad\Rules\LaunchpadPurchasableRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class LaunchpadPurchaseRequest extends FormRequest
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
            'id' => ['bail', 'integer', 'numeric', 'required', 'exists:launchpads', new LaunchpadPurchasableRule()],
            'amount' => ['bail', 'required', 'numeric', new LaunchpadAmountRule()],
        ];
    }
}
