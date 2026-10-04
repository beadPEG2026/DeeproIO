<?php

namespace App\Modules\P2P\Http\Requests\Web\PeerTrade;

use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerPaymentMethodFormRequest extends FormRequest
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
            'id' => ['sometimes', 'required', 'numeric', 'integer','exists:peer_payment_methods'],
            'title' => ['bail', 'required', 'max:149', 'min:1'],
            'status' => ['required', 'boolean'],
        ];
    }
}
