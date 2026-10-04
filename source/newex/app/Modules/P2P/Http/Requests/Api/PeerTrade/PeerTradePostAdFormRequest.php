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
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerTradePostAdFormRequest extends FormRequest
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
            'coin' => ['bail', 'required', new PeerTradeCoinRule()],
            'fiat' => ['bail', 'required', new PeerTradeFiatRule()],
            'fixed_price' => ['bail', 'required_if:type,fixed', new PeerTradeFixedPriceRule()],
            'floated_price' => ['bail', 'required_if:type,float', new PeerTradeFloatPriceRule()],
            'side' => ['bail', 'required', 'in:buy,sell'],
            'type' => ['bail', 'required', 'in:fixed,float'],
            'amount' => ['bail', 'required', new PeerTradeAmountRule()],
            'min_amount' => ['bail', 'required', new PeerTradeMinAmountRule()],
            'max_amount' => ['bail', 'required', new PeerTradeMaxAmountRule()],
            'paymentMethods' => ['bail', 'required', new PeerTradePaymentMethodRule()],
            'regions' => ['bail', new PeerTradeRegionRule()],
            'timeframe' => ['bail', 'required', 'in:15,30,45,60,120,180,360'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'auto_reply' => ['sometimes', 'nullable', 'string', 'max:1000']
        ];
    }
}
