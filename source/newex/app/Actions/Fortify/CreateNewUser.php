<?php

namespace App\Actions\Fortify;

use App\Http\Controllers\Api\v1\EmailVerificationCodeController;
use App\Mail\Users\AdminUserRegistered;
use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Setting;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array  $input
     * @return \App\Models\User\User
     */
    public function create(array $input)
    {
        if (config('app.readonly')) {
            Validator::make($input, [
                'email' => 'required',
            ], [
                'required' => 'In Demo Version we enabled READ ONLY mode to protect our demo content.',
            ])->validate();
        }

        $status = setting('recaptcha.status', false);

        $siteKey = config('captcha.sitekey');
        $siteSecret = config('captcha.secret');

        /*
         * 现在注册要求：
         * 1. email 必填，存 users.email
         * 2. email_code 校验邮箱验证码
         *
         * 手机号和手机验证码已取消。
         */
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $input['email_code'] = trim((string) ($input['email_code'] ?? ''));

        $validationRules = [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'email_code' => ['required', 'digits:6'],

            'password' => $this->passwordRules(),
            'terms' => ['required', 'accepted'],
        ];

        if ($status && $siteKey && $siteSecret) {
            $validationRules['g-recaptcha-response'] = ['required', 'captcha'];
        }

        $requireReferral = Setting::get('general.registration_referral_required', false);

        $input['referral'] = $input['referral'] ?? request()->get('referral');
        $referral = app(\App\Services\Referral\ExchangeInvitations::class)->resolve($input['referral'], (bool)$requireReferral);

        $customMessages = [
            'email.required' => 'Email is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email is already registered.',

            'email_code.required' => 'Email verification code is required.',
            'email_code.digits' => 'Email verification code must be 6 digits.',

        ];

        Validator::make($input, $validationRules, $customMessages)->validate();

        if (!EmailVerificationCodeController::verifyEmailCode($input['email'], $input['email_code'])) {
            Validator::make([], [])->after(function ($validator) {
                $validator->errors()->add('email_code', 'Invalid or expired email verification code.');
            })->validate();
        }

        $ip = request()->ip();

        $user = DB::transaction(function () use ($input, $referral, $ip) {
            $user = User::create([
                'name' => $input['email'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'referral_code' => $this->getUniqueReferralCode(),
                'referral_id' => $referral ? $referral->id : null,
                'zc_ip' => $ip,
                'login_ip' => $ip,
            ]);

            $user->syncRoles(['user']);
            $user->syncPermissions([]);

            // The required single-use email code was verified above.
            $user->email_verified_at = Carbon::now();
            $user->save();

            if (config('app.readonly')) {
                $user->email_verified_at = Carbon::now();
                $user->kyc_verified_at = Carbon::now();
                $user->update();

                $currencyUSDT = Currency::where('symbol', 'USDT')->first();
                $currencyBTC = Currency::where('symbol', 'BTC')->first();

                if ($currencyUSDT) {
                    $wallet = Wallet::where('user_id', $user->id)
                        ->where('currency_id', $currencyUSDT->id)
                        ->first();

                    if ($wallet) {
                        $wallet->balance_in_wallet = 100;
                        $wallet->update();
                    }
                }

                if ($currencyBTC) {
                    $wallet = Wallet::where('user_id', $user->id)
                        ->where('currency_id', $currencyBTC->id)
                        ->first();

                    if ($wallet) {
                        $wallet->balance_in_wallet = 0.01;
                        $wallet->update();
                    }
                }
            }

            return $user;
        });
        EmailVerificationCodeController::forgetEmailCode($input['email']);

        /*
         * Admin Email Notification
         */
        $adminEmail = Setting::get('notification.admin_email', false);
        $notificationAllowed = Setting::get('notification.new_user_registered', false);

        if ($adminEmail && $notificationAllowed) {
            $route = route('admin.users') . '?search=' . $input['email'];
            Mail::to($adminEmail)->queue(new AdminUserRegistered($input['email'], $route));
        }

        return $user;
    }

    public function getUniqueReferralCode()
    {
        $str = generate_string();

        if (User::where('referral_code', $str)->exists()) {
            return $this->getUniqueReferralCode();
        }

        return $str;
    }
}