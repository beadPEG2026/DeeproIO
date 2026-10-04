<?php

namespace App\Modules\Merchant\Http\Middleware;

use App\Modules\Merchant\Services\MerchantAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantApiAuthenticate
{
    public function __construct(
        protected MerchantAuthService $authService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $result = $this->authService->authenticate($request);

            // Attach merchant and api_key to request
            $request->attributes->set('merchant', $result['merchant']);
            $request->attributes->set('api_key', $result['api_key']);

            // Also add to request for convenience
            $request->merge([
                'merchant' => $result['merchant'],
                'api_key' => $result['api_key'],
            ]);

            // Check IP whitelist
            if (!$this->authService->validateIpWhitelist($result['api_key'], $request->ip())) {
                return $this->errorResponse(
                    'IP_NOT_WHITELISTED',
                    'Request IP is not in the allowed whitelist',
                    403
                );
            }

            return $next($request);

        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();

            // Rate limit exceeded
            if ($code === 429) {
                return $this->errorResponse(
                    'RATE_LIMIT_EXCEEDED',
                    $e->getMessage(),
                    429,
                    ['Retry-After' => 60]
                );
            }

            return $this->errorResponse(
                'AUTHENTICATION_FAILED',
                $e->getMessage(),
                401
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'AUTHENTICATION_ERROR',
                'An error occurred during authentication',
                500
            );
        }
    }

    /**
     * Return error response
     *
     * @param string $code
     * @param string $message
     * @param int $status
     * @param array $headers
     * @return Response
     */
    protected function errorResponse(string $code, string $message, int $status, array $headers = []): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status, $headers);
    }
}
