<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade;

use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradePostUserPaymentFormRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradePostUserPaymentIdRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradePostUserPostIdRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerTradePostUserPaymentMethodFormRequest extends FormRequest
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
            'id' => ['bail', 'required', new PeerTradePostUserPaymentIdRule()],
            'post_id' => ['bail', new PeerTradePostUserPostIdRule()],
            'form' => ['bail', new PeerTradePostUserPaymentFormRule()],
        ];
    }
}
