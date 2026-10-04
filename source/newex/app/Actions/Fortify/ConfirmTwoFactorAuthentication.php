<?php

namespace App\Actions\Fortify;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

class ConfirmTwoFactorAuthentication extends \Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication
{
    protected $provider;

    public function __construct(TwoFactorAuthenticationProvider $provider)
    {
        $this->provider = $provider;
    }

    public function __invoke($user, $code)
    {
        $pending = session('two_factor_pending');

        if (empty($pending['secret']) || empty($pending['recovery_codes'])) {
            throw ValidationException::withMessages([
                'code' => [__('Please start two factor authentication setup again.')],
            ])->errorBag('confirmTwoFactorAuthentication');
        }

        $secret = Fortify::currentEncrypter()->decrypt($pending['secret']);

        if (empty($code) || ! $this->provider->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ])->errorBag('confirmTwoFactorAuthentication');
        }

        $user->forceFill([
            'two_factor_secret' => $pending['secret'],
            'two_factor_recovery_codes' => $pending['recovery_codes'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        session()->forget('two_factor_pending');

        TwoFactorAuthenticationEnabled::dispatch($user);
        TwoFactorAuthenticationConfirmed::dispatch($user);
    }
}
