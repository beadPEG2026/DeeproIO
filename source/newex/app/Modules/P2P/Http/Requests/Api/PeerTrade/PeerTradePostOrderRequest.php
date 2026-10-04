<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade;

use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeCoinRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFiatRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFixedPriceRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFloatPriceRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeMaxAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeMinAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeOrderAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeOrderPaymentMethodRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeOrderStatusRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradePaymentMethodRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerTradePostOrderRequest extends FormRequest
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
            'ad_id' => ['bail', 'required', new PeerTradeOrderStatusRule()],
            'payment_method' => ['bail', 'required', new PeerTradeOrderPaymentMethodRule()],
            'amount' => ['bail', 'required', new PeerTradeOrderAmountRule()],
        ];
    }
}
