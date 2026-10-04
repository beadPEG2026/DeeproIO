<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade;

use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeCoinRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFiatRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFixedPriceRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFloatPriceRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeMaxAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeMinAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradePaymentMethodRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeRegionRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerUserBlockRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerUserBlacklistFormRequest extends FormRequest
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
            'user' => ['bail', 'required', new PeerUserBlockRule()],
            'type' => ['bail', 'required', 'between:1,5'],
            'reason' => ['bail', 'required_if:type,5', 'max:200'],
        ];
    }
}
