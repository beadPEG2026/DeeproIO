<?php

namespace App\Modules\Merchant\Models;

use App\Modules\Merchant\Models\Traits\Relations\MerchantWebhookRelation;
use App\Modules\Merchant\Models\Traits\Scopes\MerchantWebhookScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantWebhook extends Model
{
    use HasFactory, HasUuids, MerchantWebhookRelation, MerchantWebhookScope;

    protected $table = 'merchant_webhooks';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'invoice_id',
        'event_type',
        'priority',
        'idempotency_key',
        'payload',
        'payload_hash',
        'webhook_url',
        'webhook_secret_version',
        'status',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'last_attempt_at',
        'last_response_code',
        'last_response_body',
        'last_failure_reason',
        'last_response_time_ms',
        'delivered_at',
        'failed_at',
        'circuit_breaker_active',
    ];

    protected $casts = [
        'payload' => 'array',
        'circuit_breaker_active' => 'boolean',
        'next_retry_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_PENDING_RETRY = 'pending_retry';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

    /**
     * Priority constants
     */
    public const PRIORITY_CRITICAL = 'critical';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW = 'low';

    /**
     * Event type constants
     */
    public const EVENT_INVOICE_CREATED = 'invoice.created';
    public const EVENT_INVOICE_PENDING = 'invoice.pending';
    public const EVENT_INVOICE_PAYMENT_DETECTING = 'invoice.payment_detecting';
    public const EVENT_INVOICE_CONFIRMING = 'invoice.confirming';
    public const EVENT_INVOICE_PAID = 'invoice.paid';
    public const EVENT_INVOICE_UNDERPAID = 'invoice.underpaid';
    public const EVENT_INVOICE_OVERPAID = 'invoice.overpaid';
    public const EVENT_INVOICE_SETTLED = 'invoice.settled';
    public const EVENT_INVOICE_EXPIRED = 'invoice.expired';
    public const EVENT_INVOICE_CANCELLED = 'invoice.cancelled';
    public const EVENT_INVOICE_FAILED = 'invoice.failed';
    public const EVENT_INVOICE_LATE_PAYMENT = 'invoice.late_payment';
    public const EVENT_REFUND_INITIATED = 'refund.initiated';
    public const EVENT_REFUND_COMPLETED = 'refund.completed';

    /**
     * Retry delays in seconds (exponential backoff)
     */
    public const RETRY_DELAYS = [
        1 => 30,      // 30 seconds
        2 => 120,     // 2 minutes
        3 => 600,     // 10 minutes
        4 => 3600,    // 1 hour
        5 => 14400,   // 4 hours
    ];

    /**
     * Check if webhook can be retried
     */
    public function canRetry(): bool
    {
        return $this->attempt_count < $this->max_attempts
            && !in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_SKIPPED_DUPLICATE]);
    }

    /**
     * Schedule next retry
     */
    public function scheduleRetry(): void
    {
        $this->attempt_count++;
        
        $delay = self::RETRY_DELAYS[$this->attempt_count] ?? 86400; // Default to 24 hours
        
        $this->status = self::STATUS_PENDING_RETRY;
        $this->next_retry_at = now()->addSeconds($delay);
        $this->save();
    }

    /**
     * Mark as delivered
     */
    public function markDelivered(int $responseCode, ?string $responseBody = null, ?int $responseTimeMs = null): void
    {
        $this->status = self::STATUS_DELIVERED;
        $this->delivered_at = now();
        $this->last_response_code = $responseCode;
        $this->last_response_body = $responseBody;
        $this->last_response_time_ms = $responseTimeMs;
        $this->save();
    }

    /**
     * Mark as failed
     */
    public function markFailed(string $reason, ?int $responseCode = null): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failed_at = now();
        $this->last_failure_reason = $reason;
        $this->last_response_code = $responseCode;
        $this->save();
    }

    /**
     * Record attempt (does NOT increment attempt_count - that's done in scheduleRetry)
     */
    public function recordAttempt(int $responseCode, ?string $responseBody, ?int $responseTimeMs, bool $success): void
    {
        $this->last_attempt_at = now();
        $this->last_response_code = $responseCode;
        $this->last_response_body = $responseBody;
        $this->last_response_time_ms = $responseTimeMs;
        // Note: attempt_count is incremented in scheduleRetry() to avoid double increment
        $this->save();
    }

    /**
     * Get priority order for sorting
     */
    public function getPriorityOrder(): int
    {
        return match ($this->priority) {
            self::PRIORITY_CRITICAL => 1,
            self::PRIORITY_HIGH => 2,
            self::PRIORITY_NORMAL => 3,
            self::PRIORITY_LOW => 4,
            default => 5,
        };
    }

    /**
     * Determine priority based on event type
     */
    public static function getPriorityForEvent(string $eventType): string
    {
        return match ($eventType) {
            self::EVENT_INVOICE_PAID, self::EVENT_INVOICE_SETTLED => self::PRIORITY_CRITICAL,
            self::EVENT_INVOICE_UNDERPAID, self::EVENT_INVOICE_OVERPAID, 
            self::EVENT_INVOICE_PAYMENT_DETECTING, self::EVENT_REFUND_INITIATED,
            self::EVENT_REFUND_COMPLETED => self::PRIORITY_HIGH,
            self::EVENT_INVOICE_EXPIRED, self::EVENT_INVOICE_CANCELLED,
            self::EVENT_INVOICE_FAILED => self::PRIORITY_NORMAL,
            default => self::PRIORITY_NORMAL,
        };
    }
}
