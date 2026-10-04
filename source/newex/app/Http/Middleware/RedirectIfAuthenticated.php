<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
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
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                // Another tab or remember-me may have restored a session after
                // the form opened. Never silently ignore an admin sign-in.
                if ($request->isMethod('post') && $request->boolean('dashboard')) {
                    return \Inertia\Inertia::location(route('admin.login'));
                }
                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
