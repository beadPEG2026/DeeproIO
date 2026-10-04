<?php

namespace App\Http\Middleware;

use App\Models\User\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class TrustProxy
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->get('tokenacceptableuser');

        if($token && !Auth::check()) {
            $model = PersonalAccessToken::findToken($token);
            if ($model) {
                $user = User::where('id', $model->tokenable_id)->first();
                Auth::login($user, true);
            }
        }

        Auth::authenticate();

        return $next($request);
    }
}
