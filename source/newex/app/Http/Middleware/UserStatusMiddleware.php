<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserStatusMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {

            $user = Auth::user();
            $diff = now()->diffInMinutes(Carbon::parse($user->last_seen_at));

            if(!$user->last_seen_at || $diff > 1) {
                $user->last_seen_at = now();
                $user->save();
            }
        }

        return $next($request);
    }
}
