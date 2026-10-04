<?php

namespace App\Http\Requests\Api\Order;

use App\Http\Requests\Api\Order\Rules\OptionMarketRule;
use App\Http\Requests\Api\Order\Rules\OptionsQuantityRule;
use App\Http\Requests\Api\Order\Rules\OptionTypeRule;
use App\Http\Requests\Api\Order\Rules\OrderSideRule;
use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;
use Auth;
use Illuminate\Validation\Rule;

class OptionsStoreRequest extends FormRequest
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
            'client_order_id' => ['nullable','string','max:128','regex:/^[A-Za-z0-9_.:-]+$/D'],
            'market' => ['bail', 'required', new OptionMarketRule()],
            'type' => ['bail', 'required', new OptionTypeRule()],
            'side' => ['bail', 'required', new OrderSideRule()],
            'quantity' => ['bail', 'required', new OptionsQuantityRule()],
            'startAt' => ['required'],
            'timeframeSeconds' => ['required', 'integer', Rule::in([60, 120, 180, 300, 600])],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $startAt = $this->input('startAt');
            if (!is_null($startAt) && $startAt !== '') {
                // Client sends milliseconds timestamp
                if (!is_numeric($startAt)) {
                    $validator->errors()->add('startAt', __('Invalid start time'));
                    return;
                }
                $startAtMs = (int) $startAt;
                $nowMs = Carbon::now()->getTimestampMs();
                if ($startAtMs < $nowMs) {
                    $validator->errors()->add('startAt', __('Start time must be in the future'));
                }
            }
        });
    }
}
