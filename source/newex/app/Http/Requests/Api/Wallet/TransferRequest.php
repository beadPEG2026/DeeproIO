<?php

namespace App\Http\Requests\Api\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'currency_id' => 'required|integer|exists:currencies,id',
            'amount' => 'required|numeric|min:0.00000001',

            // 支持全部 6 个方向
            'direction' => 'required|in:to_trade,to_funding,to_lc,from_lc,trade_to_lc,lc_to_trade',
        ];
    }

    public function messages()
    {
        return [
            'currency_id.required' => __('Currency is required'),
            'currency_id.exists' => __('Selected currency is invalid'),
            'amount.required' => __('Amount is required'),
            'amount.numeric' => __('Amount must be numeric'),
            'amount.min' => __('Amount must be greater than zero'),
            'direction.required' => __('Transfer direction is required'),
            'direction.in' => __('Invalid transfer direction'),
        ];
    }
}