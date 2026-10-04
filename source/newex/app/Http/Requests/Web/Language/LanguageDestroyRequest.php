<?php

namespace App\Http\Requests\Web\Language;

use App\Http\Requests\Web\Language\Rules\LanguageDestroyRule;
use Illuminate\Foundation\Http\FormRequest;

class LanguageDestroyRequest extends FormRequest
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
            'id' => ['required', 'integer', 'numeric', new LanguageDestroyRule()],
        ];
    }
}
