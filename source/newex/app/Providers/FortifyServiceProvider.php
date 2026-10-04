<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ReCaptchaValidation;
use App\Actions\Fortify\LoginValidation;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Models\User\User;
use App\Support\AdminAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(
            \Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class,
            \App\Actions\Fortify\EnableTwoFactorAuthentication::class
        );

        $this->app->bind(
            \Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication::class,
            \App\Actions\Fortify\ConfirmTwoFactorAuthentication::class
        );

        $this->app->bind(
            \Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class,
            \App\Actions\Fortify\DisableTwoFactorAuthentication::class
        );

        $this->app->bind(
            \Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController::class,
            \App\Http\Controllers\Fortify\TwoFactorQrCodeController::class
        );

        $this->app->bind(
            \Laravel\Fortify\Contracts\LoginResponse::class,
            LoginResponse::class,
        );

        $this->app->singleton(
            \Laravel\Fortify\Contracts\TwoFactorLoginResponse::class,
            LoginResponse::class,
        );

        $this->app->singleton(
            \Laravel\Fortify\Contracts\LogoutResponse::class,
            LogoutResponse::class
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $identifier = $this->normalizeLoginIdentifier(
                $request->input('email', $request->boolean('dashboard') ? $request->input('phone', '') : '')
            );

            if (!$identifier) {
                $identifier = $request->ip();
            }

            return Limit::perMinute(5)->by($identifier . '|' . $request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        Fortify::authenticateThrough(function (Request $request) {
            return array_filter([
                config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
                LoginValidation::class,
                ReCaptchaValidation::class,
                RedirectIfTwoFactorAuthenticatable::class,
                AttemptToAuthenticate::class,
                PrepareAuthenticatedSession::class,
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            $login = $this->normalizeLoginIdentifier(
                $request->input('email', $request->boolean('dashboard') ? $request->input('phone', '') : '')
            );

            $emailLogin = Validator::make(['email' => $login], ['email' => ['required', 'email']])->passes();
            if (!$login || (!$emailLogin && !$request->boolean('dashboard'))) {
                return null;
            }

            $ip = $this->getClientIp($request);

            // Legacy administrator identifiers remain stored in users.email.
            $user = User::where('email', $login)
                ->where('deactivated', false)
                ->authorizable()
                ->first();

            // A public phone account cannot bypass email-only login by setting dashboard=true.
            if ($user && (!$emailLogin && !AdminAccess::allows($user))) {
                return null;
            }

            if ($user && Hash::check($request->password, $user->password)) {
                $user->login_ip = $ip;
                $user->save();

                return $user;
            }

            return null;
        });
    }

    protected function normalizeLoginIdentifier($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        /**
         * 邮箱：统一小写，避免大小写导致查不到。
         */
        if (str_contains($value, '@')) {
            return strtolower($value);
        }

        /**
         * 手机号：去掉空格和横线。
         * 保留 + 号，例如 +85212345678。
         */
        return preg_replace('/[\s\-]+/', '', $value);
    }

    protected function getClientIp(Request $request)
    {
        $headers = [
            'CF-Connecting-IP',
            'X-Forwarded-For',
            'X-Real-IP',
        ];

        foreach ($headers as $header) {
            $ip = $request->header($header);

            if (!$ip) {
                continue;
            }

            $ip = explode(',', $ip)[0];
            $ip = trim($ip);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $request->ip();
    }
}
