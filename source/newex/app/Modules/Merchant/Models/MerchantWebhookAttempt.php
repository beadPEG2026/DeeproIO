<?php

namespace App\Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantWebhookAttempt extends Model
{
    use HasFactory;

    protected $table = 'merchant_webhook_attempts';

    public $timestamps = false;

    protected $fillable = [
        'webhook_id',
        'attempt_number',
        'webhook_url',
        'request_headers',
        'request_signature',
        'response_code',
        'response_body',
        'response_headers',
        'response_time_ms',
        'status',
        'error_message',
        'error_type',
        'attempted_at',
    ];

    protected $casts = [
        'request_headers' => 'array',
        'response_headers' => 'array',
        'attempted_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_CONNECTION_ERROR = 'connection_error';

    /**
     * Error type constants
     */
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_CONNECTION_REFUSED = 'connection_refused';
    public const ERROR_SSL_ERROR = 'ssl_error';
    public const ERROR_HTTP_ERROR = 'http_error';
    public const ERROR_DNS_ERROR = 'dns_error';

    /**
     * Webhook this attempt belongs to
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(MerchantWebhook::class, 'webhook_id');
    }

    /**
     * Check if attempt was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if response code indicates success
     */
    public function hasSuccessfulResponseCode(): bool
    {
        return $this->response_code >= 200 && $this->response_code < 300;
    }

    /**
     * Record a successful attempt
     */
    public static function recordSuccess(
        MerchantWebhook $webhook,
        int $responseCode,
        ?string $responseBody,
        ?array $responseHeaders,
        int $responseTimeMs
    ): self {
        return self::create([
            'webhook_id' => $webhook->id,
            'attempt_number' => $webhook->attempt_count + 1,
            'webhook_url' => $webhook->webhook_url,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'response_headers' => $responseHeaders,
            'response_time_ms' => $responseTimeMs,
            'status' => self::STATUS_SUCCESS,
            'attempted_at' => now(),
        ]);
    }

    /**
     * Record a failed attempt
     */
    public static function recordFailure(
        MerchantWebhook $webhook,
        string $status,
        ?int $responseCode,
        ?string $responseBody,
        ?string $errorMessage,
        ?string $errorType,
        ?int $responseTimeMs = null
    ): self {
        return self::create([
            'webhook_id' => $webhook->id,
            'attempt_number' => $webhook->attempt_count + 1,
            'webhook_url' => $webhook->webhook_url,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'response_time_ms' => $responseTimeMs,
            'status' => $status,
            'error_message' => $errorMessage,
            'error_type' => $errorType,
            'attempted_at' => now(),
        ]);
    }
}
