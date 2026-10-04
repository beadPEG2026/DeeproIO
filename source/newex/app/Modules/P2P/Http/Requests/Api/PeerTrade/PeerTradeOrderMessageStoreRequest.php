<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade;

use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeChatOrderidRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerTradeOrderMessageStoreRequest extends FormRequest
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
            'order_id' => ['bail', 'required', new PeerTradeChatOrderidRule()],
            'message' => ['bail', 'required', 'string', 'max:500'],
        ];
    }
}
