<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IpCountryResolver
{
    private const CZECH_COUNTRY_CODE = 'CZ';

    public function isCzechRequest(Request $request, $user = null): bool
    {
        return $this->resolveCountryCode($request, $user) === self::CZECH_COUNTRY_CODE;
    }

    public function resolveCountryCode(Request $request, $user = null): ?string
    {
        $headerCountryCode = $this->resolveHeaderCountryCode($request);

        if ($headerCountryCode !== null) {
            return $headerCountryCode;
        }

        $ip = $this->resolveClientIp($request);

        if ($ip === null) {
            return null;
        }

        $storedCountryCode = $this->resolveStoredCountryCode($ip, $user);

        if ($storedCountryCode !== null) {
            return $storedCountryCode;
        }

        if ($this->isPrivateOrReservedIp($ip)) {
            return null;
        }

        $cacheKey = 'kyc_ip_country_code:' . md5($ip);
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached !== '' ? $cached : null;
        }

        $countryCode = $this->queryCountryCode($ip);

        Cache::put(
            $cacheKey,
            $countryCode ?? '',
            $countryCode !== null ? now()->addDays(30) : now()->addHours(1)
        );

        return $countryCode;
    }

    private function resolveHeaderCountryCode(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country'] as $header) {
            $countryCode = $this->normalizeCountryCode($request->header($header));

            if ($countryCode !== null) {
                return $countryCode;
            }
        }

        return null;
    }

    private function resolveClientIp(Request $request): ?string
    {
        foreach (['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'] as $header) {
            $value = trim((string) $request->header($header));

            if ($value === '') {
                continue;
            }

            $ip = trim(explode(',', $value)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        $ip = trim((string) $request->ip());

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function resolveStoredCountryCode(string $ip, $user): ?string
    {
        if (!$user || trim((string) ($user->login_ip ?? '')) !== $ip) {
            return null;
        }

        return $this->extractCountryCodeFromLocation($user->ip_location ?? null);
    }

    private function extractCountryCodeFromLocation($location): ?string
    {
        $location = trim((string) $location);

        if ($location === '') {
            return null;
        }

        if (preg_match('/\(([A-Z]{2})\)/i', $location, $matches)) {
            return $this->normalizeCountryCode($matches[1] ?? null);
        }

        if (preg_match('/^(CZ)(?:\b|[\/\s-])/i', $location, $matches)) {
            return $this->normalizeCountryCode($matches[1] ?? null);
        }

        if (preg_match('/捷克|Czechia|Czech Republic|Česko/iu', $location)) {
            return self::CZECH_COUNTRY_CODE;
        }

        return null;
    }

    private function queryCountryCode(string $ip): ?string
    {
        try {
            $response = Http::connectTimeout(1)
                ->timeout(2)
                ->acceptJson()
                ->get('https://ipwho.is/' . $ip);

            if ($response->ok()) {
                $data = $response->json();

                if (is_array($data) && !empty($data['success'])) {
                    $countryCode = $this->normalizeCountryCode($data['country_code'] ?? null);

                    if ($countryCode !== null) {
                        return $countryCode;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the secondary provider.
        }

        try {
            $response = Http::connectTimeout(1)
                ->timeout(2)
                ->acceptJson()
                ->get('https://ipapi.co/' . $ip . '/json/');

            if (!$response->ok()) {
                return null;
            }

            $data = $response->json();

            if (!is_array($data) || !empty($data['error'])) {
                return null;
            }

            return $this->normalizeCountryCode($data['country_code'] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeCountryCode($countryCode): ?string
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return null;
        }

        if (in_array($countryCode, ['XX', 'T1'], true)) {
            return null;
        }

        return $countryCode;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
