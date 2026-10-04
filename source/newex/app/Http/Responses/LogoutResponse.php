<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function toResponse($request)
    {
        // Fortify has already invalidated the session and rotated the CSRF token.
        // Only a fixed internal destination is accepted, never a submitted URL.
        if ($request->boolean('admin_login')) {
            return Inertia::location(route('admin.login'));
        }

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : Inertia::location(config('fortify.home'));
    }
}
