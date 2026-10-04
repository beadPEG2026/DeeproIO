<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;

class DisableTwoFactorAuthentication extends \Laravel\Fortify\Actions\DisableTwoFactorAuthentication
{
    public function __invoke($user)
    {
        session()->forget('two_factor_pending');

        if (! is_null($user->two_factor_secret) ||
            ! is_null($user->two_factor_recovery_codes) ||
            ! is_null($user->two_factor_confirmed_at)) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
            ] + (Fortify::confirmsTwoFactorAuthentication() || ! is_null($user->two_factor_confirmed_at) ? [
                'two_factor_confirmed_at' => null,
            ] : []))->save();

            TwoFactorAuthenticationDisabled::dispatch($user);
        }
    }
}
