<?php

namespace App\Http\Requests\Web\Launchpad;

use App\Http\Requests\Web\Launchpad\Rules\LaunchpadDecimalRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class LaunchpadFormRequest extends FormRequest
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
    public function attributes(): array { return ['min_amount'=>__('Minimum amount'), 'max_amount'=>__('Maximum amount'), 'allowed_days'=>__('Allowed days'), 'rewards_percentage'=>__('Reward percentage'), 'currency_id'=>__('Currency'), 'network_id'=>__('Network'), 'rate'=>__('Rate'), 'min_buy'=>__('Minimum purchase'), 'max_buy'=>__('Maximum purchase'), 'soft_cap'=>__('Soft cap'), 'hard_cap'=>__('Hard cap'), 'start_time'=>__('Start time'), 'end_time'=>__('End time'), 'status'=>__('Status'), 'name'=>__('Name'), 'description'=>__('Description'), 'staking_type'=>__('Product type')]; }

    public function rules()
    {
        return [
            'id' => ['sometimes', 'integer', 'numeric', 'required', 'exists:launchpads'],
            'name' => ['required', 'max:150'],
            'description' => ['required', 'max:10000'],
            'currency_id' => ['required', 'exists:currencies,id'],
            'network_id' => ['required', 'exists:networks,id'],
            'rate' => ['required', new LaunchpadDecimalRule()],
            'min_buy' => ['required', new LaunchpadDecimalRule()],
            'max_buy' => ['bail', 'required', 'numeric', new LaunchpadDecimalRule(), 'gte:min_buy'],
            'soft_cap' => ['required', new LaunchpadDecimalRule()],
            'hard_cap' => ['bail', 'required', 'numeric', new LaunchpadDecimalRule(), 'gte:soft_cap'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'status' => ['required', 'boolean'],
        ];
    }
}
