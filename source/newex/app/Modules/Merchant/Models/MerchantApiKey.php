<?php

namespace App\Modules\Merchant\Models;

use App\Modules\Merchant\Models\Traits\Relations\MerchantApiKeyRelation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantApiKey extends Model
{
    use HasFactory, HasUuids, MerchantApiKeyRelation;

    protected $table = 'merchant_api_keys';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'name',
        'key_prefix',
        'public_key',
        'secret_key_hash',
        'secret_key_last4',
        'environment',
        'permissions',
        'ip_whitelist',
        'allowed_origins',
        'rate_limit_per_minute',
        'rate_limit_per_hour',
        'is_active',
        'expires_at',
        'last_used_at',
        'last_used_ip',
        'created_by',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'permissions' => 'array',
        'ip_whitelist' => 'array',
        'allowed_origins' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'secret_key_hash',
    ];

    /**
     * Environment constants
     */
    public const ENV_LIVE = 'live';
    public const ENV_SANDBOX = 'sandbox';

    /**
     * Permission constants
     */
    public const PERMISSION_INVOICE_CREATE = 'invoice:create';
    public const PERMISSION_INVOICE_READ = 'invoice:read';
    public const PERMISSION_INVOICE_CANCEL = 'invoice:cancel';
    public const PERMISSION_WEBHOOK_READ = 'webhook:read';
    public const PERMISSION_WEBHOOK_RETRY = 'webhook:retry';
    public const PERMISSION_REFUND_CREATE = 'refund:create';

    /**
     * Generate new API key pair
     */
    public static function generateKeyPair(string $environment = 'live'): array
    {
        $prefix = $environment === 'live' ? 'pk_live_' : 'pk_test_';
        $secretPrefix = $environment === 'live' ? 'sk_live_' : 'sk_test_';

        $publicKey = $prefix . Str::random(32);
        $secretKey = $secretPrefix . Str::random(48);

        return [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'key_prefix' => $prefix,
            'secret_key_hash' => Hash::make($secretKey),
            'secret_key_last4' => substr($secretKey, -4),
        ];
    }

    /**
     * Verify secret key
     */
    public function verifySecretKey(string $secretKey): bool
    {
        return Hash::check($secretKey, $this->secret_key_hash);
    }

    /**
     * Check if key is valid (active, not expired, not revoked)
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if key has specific permission
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($this->permissions)) {
            return true; // No restrictions = full access
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Check if IP is allowed for this key
     */
    public function isIpAllowed(?string $ip): bool
    {
        if (empty($this->ip_whitelist)) {
            return true;
        }

        return in_array($ip, $this->ip_whitelist);
    }

    /**
     * Record key usage
     */
    public function recordUsage(?string $ip = null): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ]);
    }

    /**
     * Revoke the API key
     */
    public function revoke(?int $userId = null, ?string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $userId,
            'revocation_reason' => $reason,
        ]);
    }
}
