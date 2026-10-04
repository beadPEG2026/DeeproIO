<?php
namespace App\Actions\Fortify;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReCaptchaValidation
{
    public function __invoke(Request $request, $next)
    {
        $status = setting('recaptcha.status', false);

        $site_key = config('captcha.sitekey');
        $site_secret = config('captcha.secret');

        if($status && $site_key && $site_secret) {
            Validator::make($request->all(), [
                'g-recaptcha-response' => 'required|captcha'
            ])->validate();
        }

        return $next($request);
    }
}
