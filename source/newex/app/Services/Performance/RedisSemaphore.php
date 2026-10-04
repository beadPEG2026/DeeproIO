<?php

namespace App\Services\Performance;

use App\Exceptions\ConcurrencyLimitExceededException;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class RedisSemaphore
{
    public function run(string $name, Closure $callback)
    {
        if (!(bool) config('performance.futures_open_gate.enabled', true)) {
            return $callback();
        }

        $limit = max(1, (int) config('performance.futures_open_gate.max_concurrency', 8));
        $waitMilliseconds = max(0, (int) config('performance.futures_open_gate.wait_milliseconds', 3000));
        $leaseMilliseconds = max(5000, (int) config('performance.futures_open_gate.lease_milliseconds', 60000));
        $connection = (string) config('performance.futures_open_gate.redis_connection', 'default');
        $key = 'performance:semaphore:' . preg_replace('/[^a-zA-Z0-9:_-]/', '-', $name);
        $token = (string) Str::uuid();
        $deadline = $this->nowMilliseconds() + $waitMilliseconds;

        while (true) {
            try {
                $acquired = $this->acquire(
                    $connection,
                    $key,
                    $token,
                    $limit,
                    $leaseMilliseconds
                );
            } catch (\Throwable $e) {
                $this->safeLog('warning', 'Redis semaphore unavailable; futures opening continued without concurrency gate', [
                    'semaphore' => $name,
                    'message' => $e->getMessage(),
                ]);

                return $callback();
            }

            if ($acquired) {
                try {
                    return $callback();
                } finally {
                    $this->release($connection, $key, $token);
                }
            }

            $remaining = $deadline - $this->nowMilliseconds();

            if ($remaining <= 0) {
                throw new ConcurrencyLimitExceededException('Futures opening concurrency limit reached');
            }

            $sleepMilliseconds = min($remaining, mt_rand(35, 70));
            usleep($sleepMilliseconds * 1000);
        }
    }

    private function acquire(
        string $connection,
        string $key,
        string $token,
        int $limit,
        int $leaseMilliseconds
    ): bool {
        $now = $this->nowMilliseconds();
        $expiresAt = $now + $leaseMilliseconds;
        $script = <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])

if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[3]) then
    return 0
end

redis.call('ZADD', KEYS[1], ARGV[2], ARGV[4])
redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[5]))

return 1
LUA;

        $result = Redis::connection($connection)->eval(
            $script,
            1,
            $key,
            $now,
            $expiresAt,
            $limit,
            $token,
            $leaseMilliseconds + 1000
        );

        return (int) $result === 1;
    }

    private function release(string $connection, string $key, string $token): void
    {
        try {
            Redis::connection($connection)->zrem($key, $token);
        } catch (\Throwable $e) {
            $this->safeLog('warning', 'Redis semaphore lease release failed', [
                'semaphore_key' => $key,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function safeLog(string $level, string $message, array $context): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable $e) {
            // Logging must never change whether a financial request is executed.
        }
    }

    private function nowMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
