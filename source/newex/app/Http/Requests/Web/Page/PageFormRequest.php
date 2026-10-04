<?php

namespace App\Http\Requests\Web\Page;

use App\Http\Requests\Web\Currency\Rules\CurrencyNetworkRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencySymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTypeRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class PageFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->input('mode')!=='preview'||$this->routeIs('admin.pages.preview');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'mode'=>['required','in:draft,publish,preview'],
            'reason'=>['required_unless:mode,preview','nullable','string','min:5','max:500'],
            'revision'=>['nullable','string','size:64'],
            'is_html'=>['required','boolean'],
            'html_content'=>['nullable','string','max:1000000'],
            'id' => ['sometimes', 'required', 'numeric', 'integer','exists:pages'],
            'title' => ['bail', 'required', 'max:255', 'min:1'],
            'slug' => ['bail', 'required', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('pages')->ignore($this->route('page')?->id ?? $this->input('id'), 'id')],
            'content' => ['bail', 'required', 'string','max:1000000'],
            'title_zh-cn' => ['nullable', 'string', 'max:255'],
            'content_zh-cn' => ['nullable', 'string', 'max:1000000'],
            'html_content_zh-cn' => ['nullable', 'string', 'max:1000000'],
            'status' => ['required', 'boolean'],
            'seo_title' => ['max:255'],
            'seo_description' => ['max:1000'],
            'seo_keywords' => ['max:500'],
        ];
    }
}
