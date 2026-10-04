<?php

namespace App\Http\Requests\Api\Wallet;

use App\Http\Requests\Api\Wallet\Rules\WalletSymbolRule;
use App\Http\Requests\Api\Wallet\Rules\WalletWithdrawAmountRule;
use App\Http\Requests\Api\Wallet\Rules\WalletWithdrawNetworkRule;
use App\Http\Requests\Web\Wallet\Rules\WithdrawAddressValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawRequest extends FormRequest
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
        $isInternal = $this->boolean('internal_transfer') || $this->get('withdraw_type') === 'internal';

        $currency = \App\Models\Currency\Currency::where('symbol', $this->get('symbol'))->first();
        return [
            'withdraw_type' => ['bail', 'nullable', 'in:external,internal'],
            'internal_transfer' => ['bail', 'nullable'],

            'symbol' => ['bail', 'required', new WalletSymbolRule()],

            'amount' => [
                'bail',
                'required',
                'numeric',
                'gt:0',
                new WalletWithdrawAmountRule()
            ],

            /**
             * 內部提現不需要 network / address / memo
             */
            'network' => $isInternal
                ? ['bail', 'nullable']
                : ['bail', 'required', new WalletWithdrawNetworkRule()],

            'address' => $isInternal
                ? ['bail', 'nullable']
                : ['bail', 'required', 'max:255', new WithdrawAddressValidationRule()],

            'payment_id' => $isInternal
                ? ['bail', 'nullable']
                : ['bail', ($currency?->has_payment_id ? 'required' : 'nullable'), 'integer', 'min:0', 'max:4294967295'],

            /**
             * internal_uid 实际是 users.referral_code，例如：27GLNAGW0UIDZ2X
             */
            'internal_uid' => $isInternal
                ? ['bail', 'required', 'string', 'max:100']
                : ['bail', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages()
    {
        return [
            'internal_uid.required' => __('Please enter recipient UID'),
            'internal_uid.string' => __('Recipient UID is invalid'),
            'internal_uid.max' => __('Recipient UID is invalid'),

            'withdraw_type.in' => __('Invalid withdrawal type'),

            'network.required' => __('Please select network'),
            'address.required' => __('Please enter withdrawal address'),

            'amount.required' => __('Please enter withdrawal amount'),
            'amount.numeric' => __('Invalid amount'),
            'amount.gt' => __('Invalid amount'),
        ];
    }
}