<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade;

use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeOrderAppealAttachmentRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeOrderAppealRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerTradeOrderAppealRequest extends FormRequest
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
            'id' => ['bail', 'required', new PeerTradeOrderAppealRule()],
            'reason' => ['bail', 'required', 'string', 'max:500', 'min:1'],
            'attachments' => ['bail', 'required', new PeerTradeOrderAppealAttachmentRule()],
        ];
    }
}
