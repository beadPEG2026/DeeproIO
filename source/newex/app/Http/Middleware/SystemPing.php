<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Setting;

class SystemPing
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
        $settings = Setting::get('system-monitor.ping', false);

        if(!$settings) {
           return Redirect::route('system-monitor.ping');
        }

        return $next($request);
    }
}
