<?php

namespace App\Http\Requests\Api\Wallet;

use App\Http\Requests\Api\Wallet\Rules\WalletGetAddressRule;
use App\Http\Requests\Api\Wallet\Rules\WalletNetworkRule;
use Illuminate\Foundation\Http\FormRequest;

class GetAddressRequest extends FormRequest
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
            'symbol' => ['bail', 'required', new WalletGetAddressRule()],
            'network' => ['bail', 'required', new WalletNetworkRule()],
        ];
    }
}
