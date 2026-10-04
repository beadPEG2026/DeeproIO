<?php

namespace App\Http\Requests\Api\Market;

use App\Http\Requests\Api\Market\Rules\MarketCmcDataRule;
use App\Http\Requests\Api\Market\Rules\MarketCoingeckoDataRule;
use Illuminate\Foundation\Http\FormRequest;

class MarketCoingeckoDataRequest extends FormRequest
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
            'ticker_id' => ['bail', 'required', new MarketCoingeckoDataRule()],
        ];
    }
}
