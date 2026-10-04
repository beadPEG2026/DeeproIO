<?php

namespace App\Http\Requests\Web\Language;

use App\Http\Requests\Web\Language\Rules\LanguageKeyUniqueRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageTranslationFormRequest extends FormRequest
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
            'id' => ['sometimes', 'required', 'exists:language_translations'],
            'key' => ['bail', 'required', 'regex:/^[a-z._-]+$/', 'max:255', new LanguageKeyUniqueRule()],
            'content' => ['required', 'min:1', 'max:64000'],
        ];
    }
}
