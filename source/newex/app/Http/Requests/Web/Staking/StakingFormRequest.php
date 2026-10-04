<?php

namespace App\Http\Requests\Web\Staking;

use Illuminate\Foundation\Http\FormRequest;
use Auth;

class StakingFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    protected function prepareForValidation():void {
        $product=$this->route("staking");
        if($product instanceof \App\Models\Staking\Staking)$this->merge(["staking_type"=>(int)$product->staking_type]);
        if($this->has("reason"))$this->merge(["reason"=>trim((string)$this->input("reason"))]);
    }

    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function withValidator($validator): void {
        $validator->after(function($validator){
            if ($validator->errors()->isNotEmpty() || (int)$this->input('staking_type')!==0) return;
            $days=array_map('trim',explode(',',$this->input('allowed_days')));
            $rates=array_map('trim',explode(',',$this->input('rewards_percentage')));
            if(count($days)>20 || count(array_filter($days,fn($d)=>(float)$d>36500)))$validator->errors()->add('allowed_days',__('Invalid staking duration.'));
            if(count($days)!==count($rates))$validator->errors()->add('rewards_percentage',__('Each duration requires one reward percentage.'));
            if(count($days)!==count(array_unique($days)))$validator->errors()->add('allowed_days',__('Durations must be unique.'));
        });
    }
    public function attributes(): array { return ['min_amount'=>__('Minimum amount'), 'max_amount'=>__('Maximum amount'), 'allowed_days'=>__('Allowed days'), 'rewards_percentage'=>__('Reward percentage'), 'currency_id'=>__('Currency'), 'network_id'=>__('Network'), 'rate'=>__('Rate'), 'min_buy'=>__('Minimum purchase'), 'max_buy'=>__('Maximum purchase'), 'soft_cap'=>__('Soft cap'), 'hard_cap'=>__('Hard cap'), 'start_time'=>__('Start time'), 'end_time'=>__('End time'), 'status'=>__('Status'), 'name'=>__('Name'), 'description'=>__('Description'), 'staking_type'=>__('Product type')]; }

    public function rules()
    {

        return [
            'reason' => ['required_if:staking_type,0','nullable','string','min:5','max:500'],
            'revision'=>['nullable','string','size:64'],
            'effective_at'=>['nullable','date','after:now'],
            'apr_limit'=>['nullable','numeric','min:0','max:100000'],
            'high_apr_ack'=>['sometimes','boolean'],
            'reward_user_id'=>['nullable','integer','exists:users,id'],
            'pool_limit'=>['nullable','numeric','gt:0'],
            'id' => ['sometimes', 'required', 'numeric', 'integer','exists:staking'],
            'currency_id' => ['bail', 'required', 'exists:currencies,id'],
            'allowed_days' => ['bail', 'required', 'string', 'max:255', 'regex:/^[1-9]\d*(?:\s*,\s*[1-9]\d*)*$/D'],
            'rewards_percentage' => ['bail', 'required', 'string', 'max:255', 'regex:/^\d+(?:\.\d+)?(?:\s*,\s*\d+(?:\.\d+)?)*$/D'],
            'min_amount' => ['bail', 'required', 'numeric', 'gte:0'],
            'max_amount' => ['bail', 'required', 'numeric', 'gte:min_amount'],
            'status' => ['bail', 'required', 'in:active,sold,hidden'],
            'staking_type' => ['bail', 'required', 'integer', 'in:0,1'],
        ];
    }
}
