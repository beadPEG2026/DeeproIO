<?php

namespace App\Http\Requests\Web\Support;

use Illuminate\Foundation\Http\FormRequest;

class SupportFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'file_id' => 'nullable|integer|exists:file_uploads,id',
            'request_key' => 'required|uuid',
        ];

        $status = setting('recaptcha.status', false);
        $site_key = config('captcha.sitekey');
        $site_secret = config('captcha.secret');

        if ($status && $site_key && $site_secret) {
            $rules['g-recaptcha-response'] = ['required', 'captcha'];
        }

        return $rules;
    }
}
