<?php

namespace App\Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class MerchantApiRateLimit
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $limiterName
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $limiterName = 'merchant_api'): Response
    {
        $key = $this->resolveRequestKey($request, $limiterName);
        $limits = $this->getLimits($request, $limiterName);

        foreach ($limits as $period => $maxAttempts) {
            $periodKey = "{$key}:{$period}";

            if (RateLimiter::tooManyAttempts($periodKey, $maxAttempts)) {
                $retryAfter = RateLimiter::availableIn($periodKey);

                return $this->rateLimitResponse($maxAttempts, $retryAfter, $period);
            }

            RateLimiter::hit($periodKey, $this->getDecayTime($period));
        }

        $response = $next($request);

        // Add rate limit headers
        return $this->addRateLimitHeaders($response, $key, $limits);
    }

    /**
     * Resolve the request key for rate limiting
     *
     * @param Request $request
     * @param string $limiterName
     * @return string
     */
    protected function resolveRequestKey(Request $request, string $limiterName): string
    {
        // If authenticated, use API key ID
        $apiKey = $request->get('api_key');
        if ($apiKey) {
            return "merchant_api:{$apiKey->id}";
        }

        // For widget requests, use token + IP
        $token = $request->bearerToken();
        if ($token) {
            return "widget:" . md5($token);
        }

        // Fallback to IP
        return "{$limiterName}:" . $request->ip();
    }

    /**
     * Get rate limits based on limiter name and request
     *
     * @param Request $request
     * @param string $limiterName
     * @return array
     */
    protected function getLimits(Request $request, string $limiterName): array
    {
        // Custom limits per API key
        $apiKey = $request->get('api_key');
        if ($apiKey) {
            return [
                'minute' => $apiKey->rate_limit_per_minute ?? config('merchant_acquiring.rate_limits.api.per_minute', 60),
                'hour' => $apiKey->rate_limit_per_hour ?? config('merchant_acquiring.rate_limits.api.per_hour', 1000),
            ];
        }

        // Default limits by limiter type
        return match ($limiterName) {
            'widget' => [
                'minute' => config('merchant_acquiring.rate_limits.widget.per_minute', 120),
            ],
            'webhook_verify' => [
                'minute' => config('merchant_acquiring.rate_limits.webhook_verify.per_minute', 30),
            ],
            default => [
                'minute' => config('merchant_acquiring.rate_limits.api.per_minute', 60),
                'hour' => config('merchant_acquiring.rate_limits.api.per_hour', 1000),
            ],
        };
    }

    /**
     * Get decay time for period
     *
     * @param string $period
     * @return int
     */
    protected function getDecayTime(string $period): int
    {
        return match ($period) {
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            default => 60,
        };
    }

    /**
     * Return rate limit exceeded response
     *
     * @param int $maxAttempts
     * @param int $retryAfter
     * @param string $period
     * @return Response
     */
    protected function rateLimitResponse(int $maxAttempts, int $retryAfter, string $period): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => "Rate limit exceeded. Maximum {$maxAttempts} requests per {$period}.",
                'retry_after' => $retryAfter,
            ],
        ], 429, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + $retryAfter,
        ]);
    }

    /**
     * Add rate limit headers to response
     *
     * @param Response $response
     * @param string $key
     * @param array $limits
     * @return Response
     */
    protected function addRateLimitHeaders(Response $response, string $key, array $limits): Response
    {
        // Use minute limit for headers
        $minuteLimit = $limits['minute'] ?? 60;
        $minuteKey = "{$key}:minute";

        $remaining = max(0, $minuteLimit - RateLimiter::attempts($minuteKey));

        $response->headers->set('X-RateLimit-Limit', $minuteLimit);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', time() + 60);

        return $response;
    }
}
