<?php

namespace App\Http\Requests\Web\User;

use App\Http\Requests\Web\User\Rules\DocumentsUploadRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class KycFormRequest extends FormRequest
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
        $rules = [
            'first_name' => ['bail', 'required', 'min:1', 'max:60'],
            'last_name' => ['bail', 'required', 'min:1', 'max:60'],
            'middle_name' => ['bail', 'max:60'],
            'country_id' => ['required', 'integer', 'numeric', 'exists:countries,id'],
            'document_type' => ['bail', 'required', Rule::in(['id', 'passport', 'driver_license', 'residence_permit'])],
            'front_id' => ['bail', 'required', 'numeric', 'exists:file_uploads,id'],
            'selfie_id' => ['bail', 'required', 'numeric', 'exists:file_uploads,id'],
        ];

        $type = request()->get('document_type');
        $backRequired = $type == "id" || $type == "residence_permit";

        if($backRequired) {
            $rules['back_id'] = ['bail', new RequiredIf($backRequired), 'numeric', 'exists:file_uploads,id'];
        }

        return $rules;
    }
}
