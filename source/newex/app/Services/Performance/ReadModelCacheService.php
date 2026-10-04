<?php

namespace App\Services\Performance;

use Closure;
use Illuminate\Support\Facades\Cache;

class ReadModelCacheService
{
    public function rememberWallets(int $userId, Closure $resolver)
    {
        return $this->rememberVersioned(
            'wallets',
            $userId,
            'all',
            (int) config('performance.wallets_ttl_seconds', 30),
            $resolver
        );
    }

    public function invalidateWallets(int $userId): void
    {
        $this->incrementVersion('wallets', $userId);
    }

    public function rememberOpenFutures(int $userId, string $variant, Closure $resolver)
    {
        return $this->rememberVersioned(
            'futures-open',
            $userId,
            $variant,
            (int) config('performance.futures_open_ttl_seconds', 30),
            $resolver
        );
    }

    public function invalidateOpenFutures(int $userId): void
    {
        $this->incrementVersion('futures-open', $userId);
    }

    public function rememberDashboard(string $key, Closure $resolver)
    {
        $freshSeconds = max(5, (int) config('performance.dashboard_fresh_seconds', 30));
        $staleSeconds = max(
            $freshSeconds + 1,
            (int) config('performance.dashboard_stale_seconds', 300)
        );

        $resolved = false;
        $resolvedValue = null;
        $resolverException = null;

        try {
            return $this->store()->flexible(
                $key,
                [$freshSeconds, $staleSeconds],
                function () use ($resolver, &$resolved, &$resolvedValue, &$resolverException) {
                    try {
                        $resolvedValue = $resolver();
                        $resolved = true;

                        return $resolvedValue;
                    } catch (\Throwable $e) {
                        $resolverException = $e;
                        throw $e;
                    }
                },
                ['seconds' => max(30, $staleSeconds)]
            );
        } catch (\Throwable $e) {
            if ($resolverException instanceof \Throwable) {
                throw $resolverException;
            }

            if ($resolved) {
                return $resolvedValue;
            }

            return $resolver();
        }
    }

    private function rememberVersioned(
        string $namespace,
        int $userId,
        string $variant,
        int $ttlSeconds,
        Closure $resolver
    ) {
        if ($userId <= 0 || $ttlSeconds <= 0) {
            return $resolver();
        }

        try {
            $cache = $this->store();
            $version = (int) $cache->get($this->versionKey($namespace, $userId), 0);
            $cacheKey = $this->dataKey($namespace, $userId, $version, $variant);
            $cached = $cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }
        } catch (\Throwable $e) {
            return $resolver();
        }

        $value = $resolver();

        try {
            $cache->put($cacheKey, $value, $ttlSeconds);
        } catch (\Throwable $e) {
            // Database-backed reads remain available while Redis is unavailable.
        }

        return $value;
    }

    private function incrementVersion(string $namespace, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $this->store()->increment($this->versionKey($namespace, $userId));
        } catch (\Throwable $e) {
            // The short cache TTL is the fallback if invalidation cannot reach Redis.
        }
    }

    private function store()
    {
        return Cache::store((string) config('performance.cache_store', 'redis'));
    }

    private function versionKey(string $namespace, int $userId): string
    {
        return "performance:{$namespace}:v1:user:{$userId}:version";
    }

    private function dataKey(
        string $namespace,
        int $userId,
        int $version,
        string $variant
    ): string {
        return "performance:{$namespace}:v1:user:{$userId}:version:{$version}:" . sha1($variant);
    }
}
