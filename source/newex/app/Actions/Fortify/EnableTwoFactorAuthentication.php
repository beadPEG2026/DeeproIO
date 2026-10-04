<?php

namespace App\Actions\Fortify;

use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

class EnableTwoFactorAuthentication extends \Laravel\Fortify\Actions\EnableTwoFactorAuthentication
{
    protected $provider;

    public function __construct(TwoFactorAuthenticationProvider $provider)
    {
        $this->provider = $provider;
    }

    public function __invoke($user, $force = false)
    {
        if (! empty($user->two_factor_secret) && $force !== true) {
            return;
        }

        $secretLength = (int) config('fortify-options.two-factor-authentication.secret-length', 16);
        $secret = $this->provider->generateSecretKey($secretLength);

        session()->put('two_factor_pending', [
            'secret' => Fortify::currentEncrypter()->encrypt($secret),
            'recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(8, function () {
                return RecoveryCode::generate();
            })->all())),
        ]);
    }
}
