<?php

namespace App\Modules\Merchant\Services;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MerchantAuthService
{
    /**
     * Authenticate request and return merchant
     */
    public function authenticate(Request $request): array
    {
        // Get API key from header
        $publicKey = $request->header('X-API-Key');
        if (empty($publicKey)) {
            throw new InvalidArgumentException('Missing API key');
        }

        // Validate API key
        $apiKey = $this->validateApiKey($publicKey);

        // Validate signature
        $this->validateSignature($request, $apiKey);

        // Validate nonce (replay protection)
        $this->validateNonce($request, $apiKey);

        // Check rate limits
        $this->checkRateLimit($apiKey, $request);

        // Record usage
        $apiKey->recordUsage($request->ip());

        // Get merchant
        $merchant = $apiKey->merchant;

        if (!$merchant->isActive()) {
            throw new InvalidArgumentException('Merchant account is not active');
        }

        return [
            'merchant' => $merchant,
            'api_key' => $apiKey,
        ];
    }

    /**
     * Validate API key
     */
    protected function validateApiKey(string $publicKey): MerchantApiKey
    {
        $apiKey = MerchantApiKey::where('public_key', $publicKey)->first();

        if (!$apiKey) {
            Log::warning("Invalid API key attempted", [
                'key_prefix' => substr($publicKey, 0, 12) . '...',
            ]);
            throw new InvalidArgumentException('Invalid API key');
        }

        if (!$apiKey->isValid()) {
            $reason = match (true) {
                !$apiKey->is_active => 'API key is disabled',
                $apiKey->revoked_at !== null => 'API key has been revoked',
                $apiKey->expires_at?->isPast() => 'API key has expired',
                default => 'API key is invalid',
            };
            throw new InvalidArgumentException($reason);
        }

        return $apiKey;
    }

    /**
     * Validate request signature
     */
    protected function validateSignature(Request $request, MerchantApiKey $apiKey): void
    {
        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        if (empty($signature) || empty($timestamp)) {
            throw new InvalidArgumentException('Missing signature or timestamp');
        }

        // Check timestamp is within tolerance
        $tolerance = config('merchant_acquiring.security.api_timestamp_tolerance', 30);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new InvalidArgumentException('Request timestamp is too old');
        }

        // Build signed payload
        $method = $request->method();
        $path = $request->path();
        $body = $request->getContent();

        $signedPayload = "{$timestamp}.{$method}.{$path}.{$body}";

        // Get secret key from secure storage
        // Note: In production, the secret is hashed, so we need to verify differently
        // The signature should be computed by the client using their secret
        $expectedSignature = $this->computeSignature($signedPayload, $apiKey);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning("Invalid API signature", [
                'api_key_id' => $apiKey->id,
                'merchant_id' => $apiKey->merchant_id,
            ]);
            throw new InvalidArgumentException('Invalid signature');
        }
    }

    /**
     * Compute expected signature
     */
    protected function computeSignature(string $payload, MerchantApiKey $apiKey): string
    {
        // The merchant computes: HMAC-SHA256(payload, secret_key)
        // We verify by checking against known values
        // Since we only store hash, we use a different approach:
        // Store a signing key derived from the secret during key creation

        // For now, we'll use a simplified verification
        // In production, implement proper signature verification
        return hash_hmac(
            'sha256',
            $payload,
            $apiKey->secret_key_hash // In production, use a proper signing key
        );
    }

    /**
     * Validate nonce (replay protection)
     */
    protected function validateNonce(Request $request, MerchantApiKey $apiKey): void
    {
        $nonce = $request->header('X-Nonce');

        if (empty($nonce)) {
            throw new InvalidArgumentException('Missing nonce');
        }

        // Check if nonce was already used
        $cacheKey = "nonce:{$apiKey->merchant_id}:{$nonce}";
        $nonceExpiry = config('merchant_acquiring.security.nonce_expiry', 300);

        if (Cache::has($cacheKey)) {
            Log::warning("Nonce reuse attempted", [
                'api_key_id' => $apiKey->id,
                'nonce' => $nonce,
            ]);
            throw new InvalidArgumentException('Nonce already used (possible replay attack)');
        }

        // Store nonce
        Cache::put($cacheKey, true, $nonceExpiry);

        // Also store in database for audit
        DB::table('merchant_api_nonces')->insert([
            'nonce' => $nonce,
            'merchant_id' => $apiKey->merchant_id,
            'api_key_id' => $apiKey->id,
            'used_at' => now(),
            'expires_at' => now()->addSeconds($nonceExpiry),
        ]);
    }

    /**
     * Check rate limits
     */
    protected function checkRateLimit(MerchantApiKey $apiKey, Request $request): void
    {
        $limits = [
            'minute' => $apiKey->rate_limit_per_minute ?? 60,
            'hour' => $apiKey->rate_limit_per_hour ?? 1000,
        ];

        foreach ($limits as $period => $limit) {
            $cacheKey = "rate_limit:{$apiKey->id}:{$period}";
            $count = Cache::get($cacheKey, 0);

            if ($count >= $limit) {
                Log::warning("Rate limit exceeded", [
                    'api_key_id' => $apiKey->id,
                    'period' => $period,
                    'limit' => $limit,
                ]);

                throw new InvalidArgumentException(
                    "Rate limit exceeded. Try again later.",
                    429
                );
            }

            // Increment counter
            $ttl = $period === 'minute' ? 60 : 3600;
            Cache::put($cacheKey, $count + 1, $ttl);
        }
    }

    /**
     * Validate IP whitelist
     */
    public function validateIpWhitelist(MerchantApiKey $apiKey, string $ip): bool
    {
        // Check key-level whitelist
        if (!empty($apiKey->ip_whitelist)) {
            if (!$this->isIpInList($ip, $apiKey->ip_whitelist)) {
                return false;
            }
        }

        // Check merchant-level whitelist
        $merchant = $apiKey->merchant;
        if (!empty($merchant->ip_whitelist)) {
            if (!$this->isIpInList($ip, $merchant->ip_whitelist)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if IP is in whitelist
     */
    protected function isIpInList(string $ip, array $whitelist): bool
    {
        foreach ($whitelist as $allowed) {
            // Direct match
            if ($ip === $allowed) {
                return true;
            }

            // CIDR match
            if (str_contains($allowed, '/')) {
                if ($this->ipMatchesCidr($ip, $allowed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if IP matches CIDR range
     */
    protected function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $mask = (int) $mask;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Generate new API key pair for merchant
     */
    public function generateApiKey(
        Merchant $merchant,
        string $name,
        string $environment = 'live',
        array $permissions = [],
        ?int $createdBy = null
    ): array {
        // Generate key pair
        $keyPair = MerchantApiKey::generateKeyPair($environment);

        // Create key record
        $apiKey = MerchantApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'key_prefix' => $keyPair['key_prefix'],
            'public_key' => $keyPair['public_key'],
            'secret_key_hash' => $keyPair['secret_key_hash'],
            'secret_key_last4' => $keyPair['secret_key_last4'],
            'environment' => $environment,
            'permissions' => $permissions ?: null,
            'created_by' => $createdBy,
        ]);

        Log::info("API key created", [
            'api_key_id' => $apiKey->id,
            'merchant_id' => $merchant->id,
            'name' => $name,
            'environment' => $environment,
        ]);

        // Return full key pair (secret only shown once!)
        return [
            'api_key' => $apiKey,
            'public_key' => $keyPair['public_key'],
            'secret_key' => $keyPair['secret_key'], // Only returned once!
        ];
    }

    /**
     * Revoke API key
     */
    public function revokeApiKey(MerchantApiKey $apiKey, ?int $userId = null, ?string $reason = null): void
    {
        $apiKey->revoke($userId, $reason);

        Log::info("API key revoked", [
            'api_key_id' => $apiKey->id,
            'merchant_id' => $apiKey->merchant_id,
            'revoked_by' => $userId,
            'reason' => $reason,
        ]);
    }

    /**
     * Verify webhook signature (for merchants to verify our webhooks)
     */
    public function verifyWebhookSignature(
        string $payload,
        string $signature,
        string $timestamp,
        string $secret
    ): bool {
        // Check timestamp freshness
        $tolerance = config('merchant_acquiring.webhook.timestamp_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        // Compute expected signature
        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = 'v1=' . hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check if API key has permission
     */
    public function hasPermission(MerchantApiKey $apiKey, string $permission): bool
    {
        return $apiKey->hasPermission($permission);
    }

    /**
     * Get rate limit status for API key
     */
    public function getRateLimitStatus(MerchantApiKey $apiKey): array
    {
        return [
            'minute' => [
                'limit' => $apiKey->rate_limit_per_minute ?? 60,
                'used' => Cache::get("rate_limit:{$apiKey->id}:minute", 0),
                'remaining' => max(0, ($apiKey->rate_limit_per_minute ?? 60) - Cache::get("rate_limit:{$apiKey->id}:minute", 0)),
            ],
            'hour' => [
                'limit' => $apiKey->rate_limit_per_hour ?? 1000,
                'used' => Cache::get("rate_limit:{$apiKey->id}:hour", 0),
                'remaining' => max(0, ($apiKey->rate_limit_per_hour ?? 1000) - Cache::get("rate_limit:{$apiKey->id}:hour", 0)),
            ],
        ];
    }

    /**
     * Cleanup expired nonces
     */
    public function cleanupExpiredNonces(): int
    {
        return DB::table('merchant_api_nonces')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
