<?php

namespace App\Actions\Fortify;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoginValidation
{
    public function __invoke(Request $request, $next)
    {
        // The public form accepts email only. Keep the separate admin entry compatible.
        $adminLogin = $request->boolean('dashboard');
        $login = $request->input('email');

        if ($adminLogin && empty($login) && $request->filled('phone')) {
            $login = $request->input('phone');
        }

        $login = is_string($login) ? $this->normalizeLoginIdentifier($login) : $login;

        $request->merge([
            'email' => $login,
            'phone' => null,
        ]);

        Validator::make($request->all(), [
            'email' => $adminLogin ? ['required', 'string', 'max:255'] : ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => __('Please enter a valid email address'),
            'email.string' => __('Please enter a valid email address'),
            'email.email' => __('Please enter a valid email address'),
            'password.required' => 'The password field is required.',
        ])->validate();

        // The second-factor request does not resubmit the login form fields.
        if ($request->hasSession()) {
            $request->session()->put('auth.admin_login', $request->boolean('dashboard'));
        }

        return $next($request);
    }

    protected function normalizeLoginIdentifier($value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        /**
         * 邮箱统一小写。
         */
        if (str_contains($value, '@')) {
            return strtolower($value);
        }

        /**
         * 手机号去掉空格和横线。
         * 保留 + 号，例如 +85212345678。
         */
        return preg_replace('/[\s\-]+/', '', $value);
    }
}
