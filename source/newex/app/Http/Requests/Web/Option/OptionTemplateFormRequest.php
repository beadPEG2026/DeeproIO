<?php

namespace App\Http\Requests\Web\Option;

use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class OptionTemplateFormRequest extends FormRequest
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
            'id' => ['sometimes', 'integer', 'numeric', 'required', 'exists:options_templates'],
            'type' => ['bail', 'required'],
            'period' => ['bail', 'required', 'numeric'],
            'amount' => ['bail', 'required', 'numeric'],
            'market_id' => ['required', 'exists:markets,id'],
            'action' => ['required'],
        ];
    }
}
