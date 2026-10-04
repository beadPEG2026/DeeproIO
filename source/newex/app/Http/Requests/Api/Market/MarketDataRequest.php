<?php

namespace App\Http\Requests\Api\Market;

use App\Http\Requests\Api\Market\Rules\MarketDataRule;
use Illuminate\Foundation\Http\FormRequest;

class MarketDataRequest extends FormRequest
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
            'market' => ['bail', 'required', new MarketDataRule()],
        ];
    }
}
