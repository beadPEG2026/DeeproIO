<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * @inheritDoc
     */
    public function toResponse($request)
    {
        $adminLogin = $request->hasSession()
            ? $request->session()->pull('auth.admin_login', false)
            : false;

        if ($request->boolean('dashboard') || $adminLogin) {
            $destination = \App\Support\AdminAccess::allows($request->user())
                ? 'admin.dashboard'
                : 'admin.login';
            return Inertia::location(route($destination));
        }

        if($request->wantsJson()) {
            $token = $request->user()->createToken('auth_token')->plainTextToken;
            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        }

        $destination = config('fortify.home');
        $intended = $request->hasSession() ? $request->session()->pull('url.intended') : null;
        if (is_string($intended) && !preg_match('/[\\\\\x00-\x20\x7f]/', $intended)) {
            $parts = parse_url($intended);
            $relative = str_starts_with($intended, '/') && !str_starts_with($intended, '//');
            $sameOrigin = is_array($parts)
                && isset($parts['scheme'], $parts['host'])
                && strtolower($parts['scheme']) === $request->getScheme()
                && strtolower($parts['host']) === strtolower($request->getHost())
                && ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) === $request->getPort()
                && !isset($parts['user']) && !isset($parts['pass']);
            if ($relative || $sameOrigin) $destination = $intended;
        }

        return Inertia::location($destination);
    }
}
