<?php

namespace App\Http\Requests\Web\Article;

use App\Http\Requests\Web\Currency\Rules\CurrencyAltSymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyBankAccountRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyBankStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyBepContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTrcContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyCcExchangeRateRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyCcStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyNetworkRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencySymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTypeRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class ArticleFormRequest extends FormRequest
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

    protected function prepareForValidation()
    {
        if ($this->has('visibility_country') && $this->input('visibility_country') !== null) {
            $this->merge([
                'visibility_country' => strtoupper(trim((string) $this->input('visibility_country'))),
            ]);
        }

        if ($this->has('visibility_referral_user_id')) {
            $value = trim((string) $this->input('visibility_referral_user_id'));
            $this->merge(['visibility_referral_user_id' => $value === '' ? null : $value]);
        }

        if ($this->has('homepage_popup_excluded_countries')) {
            $value = $this->input('homepage_popup_excluded_countries');

            if (is_array($value)) {
                $value = implode(',', $value);
            }

            $value = strtoupper(trim((string) $value));
            $value = preg_replace('/[\s;；，、]+/u', ',', $value) ?? $value;
            $value = preg_replace('/,+/', ',', $value) ?? $value;
            $value = trim($value, ',');

            $this->merge([
                'homepage_popup_excluded_countries' => $value === '' ? null : $value,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => ['sometimes', 'required', 'exists:articles'],
            'title' => ['bail', 'required', 'max:254'],
            'file_id' => ['required', 'numeric', 'exists:file_uploads,id'],
            'category_id' => ['bail', 'required'],
            'slug' => ['bail', 'required', 'max:100', Rule::unique('articles')->ignore(request()->get('id', false), 'id')],
            'body' => ['bail', 'required', 'max:4294965000'],
            'status' => ['required', 'boolean'],
            'visibility_country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'visibility_referral_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'homepage_popup_enabled' => ['nullable', 'boolean'],
            'homepage_popup_excluded_countries' => [
                'nullable',
                'string',
                'max:1000',
                'regex:/^[A-Z]{2}(,[A-Z]{2})*$/',
            ],
        ];
    }

}
