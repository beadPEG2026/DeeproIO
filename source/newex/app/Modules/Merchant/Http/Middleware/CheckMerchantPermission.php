<?php

namespace App\Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMerchantPermission
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $permission
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $apiKey = $request->get('api_key');

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_AUTHENTICATED',
                    'message' => 'Authentication required',
                ],
            ], 401);
        }

        if (!$apiKey->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PERMISSION_DENIED',
                    'message' => "This API key does not have the required permission: {$permission}",
                ],
            ], 403);
        }

        return $next($request);
    }
}
