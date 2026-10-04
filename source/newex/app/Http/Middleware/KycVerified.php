<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Setting;

class KycVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  ...$guards
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $kycStatus = Setting::get('general.kyc_status');

        if($kycStatus && !auth()->user()->kyc_verified_at) {
            //return Redirect::route('user.kyc');
        }

        return $next($request);
    }
}
