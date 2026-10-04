<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Setting;

class Maintenance
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
       
        $maintenance = Setting::get('general.maintenance_status', false);

        $adminDashboardPrefix = '/exchange-control-panel';
        $adminDashboardLoginRoute = 'admin.login';

        if($request->route()->getPrefix() == $adminDashboardPrefix && $request->route()->getName() != $adminDashboardLoginRoute && !$request->user()) {
            return Redirect::route($adminDashboardLoginRoute);
        }

        if($maintenance && $request->user() && $request->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $next($request);
        }

        if($maintenance && $request->route()->getPrefix() != $adminDashboardPrefix) {

            if(($request->expectsJson() && !$request->header('X-Inertia')) || $request->is('api/*')) {
                return response()->json(['code' => 'SITE_MAINTENANCE', 'message' => __('Unfortunately the site is down for a maintenance right now')], 503)->header('Retry-After', '60')->header('Cache-Control', 'no-store');
            }

            return Redirect::route('page.maintenance');
        }

        return $next($request);
    }
}
