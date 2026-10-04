<?php

namespace App\Http\Requests\Web\Language;

use App\Http\Requests\Web\Language\Rules\LanguageDefaultRule;
use App\Http\Requests\Web\Language\Rules\LanguageStatusRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageFormRequest extends FormRequest
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
            'id' => ['sometimes', 'required', 'numeric', 'integer', 'exists:languages'],
            'name' => ['bail', 'required', 'min:1','max:15', Rule::unique('languages')->ignore(request()->get('id', false), 'id')],
            'slug' => ['bail', 'required', 'min:1','max:5', Rule::unique('languages')->ignore(request()->get('id', false), 'id')],
            'status' => ['required', 'boolean', new LanguageStatusRule()],
            'is_default' => ['required', 'boolean', new LanguageDefaultRule()],
        ];
    }
}
