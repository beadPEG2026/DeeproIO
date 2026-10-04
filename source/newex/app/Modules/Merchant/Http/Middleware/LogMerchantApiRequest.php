<?php

namespace App\Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogMerchantApiRequest
{
    /**
     * Sensitive fields to mask in logs
     */
    protected array $sensitiveFields = [
        'secret_key',
        'password',
        'api_secret',
        'webhook_secret',
        'signature',
    ];

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $requestId = uniqid('req_', true);

        // Add request ID to request
        $request->attributes->set('request_id', $requestId);

        // Log incoming request
        $this->logRequest($request, $requestId);

        $response = $next($request);

        // Log response
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logResponse($request, $response, $requestId, $duration);

        // Add request ID to response headers
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * Log incoming request
     *
     * @param Request $request
     * @param string $requestId
     * @return void
     */
    protected function logRequest(Request $request, string $requestId): void
    {
        $apiKey = $request->get('api_key');
        $merchant = $request->get('merchant');

        Log::channel('merchant_api')->info('API Request', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $this->maskSensitive($request->query()),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'merchant_id' => $merchant?->id,
            'api_key_id' => $apiKey?->id,
            'api_key_prefix' => $apiKey?->key_prefix,
        ]);
    }

    /**
     * Log response
     *
     * @param Request $request
     * @param Response $response
     * @param string $requestId
     * @param float $duration
     * @return void
     */
    protected function logResponse(Request $request, Response $response, string $requestId, float $duration): void
    {
        $statusCode = $response->getStatusCode();
        $level = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');

        Log::channel('merchant_api')->log($level, 'API Response', [
            'request_id' => $requestId,
            'status_code' => $statusCode,
            'duration_ms' => $duration,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);
    }

    /**
     * Mask sensitive fields in data
     *
     * @param array $data
     * @return array
     */
    protected function maskSensitive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields)) {
                $data[$key] = '***MASKED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->maskSensitive($value);
            }
        }

        return $data;
    }
}
