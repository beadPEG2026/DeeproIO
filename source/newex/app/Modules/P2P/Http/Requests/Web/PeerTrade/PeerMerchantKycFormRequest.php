<?php

namespace App\Modules\P2P\Http\Requests\Web\PeerTrade;

use App\Http\Requests\Web\User\Rules\DocumentsUploadRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class PeerMerchantKycFormRequest extends FormRequest
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
            'address' => ['bail', 'required', 'min:1', 'max:255'],
            'postal_code' => ['bail', 'required', 'min:1', 'max:30'],
            'city' => ['bail', 'max:70'],
            'state' => ['bail', 'max:70'],
            'document_type' => ['bail', 'required', Rule::in([
                'bank_statement',
                'mortgage_statement',
                'utility_bill',
                'internet_service',
                'telephone_bill',
                'tax',
                'building_statement',
                'body_corporate_statement'
            ])],
            'file_id' => ['bail', 'required', 'numeric', 'exists:file_uploads,id'],
        ];

        return $rules;
    }
}
