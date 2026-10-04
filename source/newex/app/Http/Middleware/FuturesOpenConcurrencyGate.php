<?php

namespace App\Http\Middleware;

use App\Exceptions\ConcurrencyLimitExceededException;
use App\Services\Performance\RedisSemaphore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FuturesOpenConcurrencyGate
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->routeIs('futures.store')) {
            return $next($request);
        }

        try {
            return app(RedisSemaphore::class)->run(
                'futures-open',
                function () use ($request, $next) {
                    return $next($request);
                }
            );
        } catch (ConcurrencyLimitExceededException $e) {
            try {
                Log::notice('Futures opening request waited for the concurrency gate and timed out', [
                    'market' => (string) $request->get('market', ''),
                    'ip' => $request->ip(),
                ]);
            } catch (\Throwable $logException) {
                // A log permission problem must not turn a controlled timeout into HTTP 500.
            }

            return response()->json([
                'message' => 'request_was_not_processed',
                'errors' => [
                    'order' => [__('A system error occurred. Please try again later.')],
                ],
            ], STATUS_VALIDATION_ERROR);
        }
    }
}
