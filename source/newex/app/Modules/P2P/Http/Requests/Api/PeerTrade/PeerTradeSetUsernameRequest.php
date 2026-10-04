<?php

namespace App\Modules\P2P\Http\Requests\Api\PeerTrade;

use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeAdIdRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeCoinRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeDeleteAdIdRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFiatRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFixedPriceRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeFloatPriceRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeMaxAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeMinAmountRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradePaymentMethodRule;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\Rules\PeerTradeUsernameRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;

class PeerTradeSetUsernameRequest extends FormRequest
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
            'username' => ['bail', 'required', new PeerTradeUsernameRule()],
        ];
    }
}
