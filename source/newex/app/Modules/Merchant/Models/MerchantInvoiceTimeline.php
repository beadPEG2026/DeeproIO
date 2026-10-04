<?php

namespace App\Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantInvoiceTimeline extends Model
{
    use HasFactory;

    protected $table = 'merchant_invoice_timeline';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'merchant_id',
        'event_type',
        'event_source',
        'old_status',
        'new_status',
        'event_data',
        'actor_type',
        'actor_id',
        'actor_ip',
        'occurred_at',
        'duration_from_previous_ms',
    ];

    protected $casts = [
        'event_data' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Event type constants
     */
    public const EVENT_CREATED = 'created';
    public const EVENT_CURRENCY_SELECTED = 'currency_selected';
    public const EVENT_RATE_LOCKED = 'rate_locked';
    public const EVENT_RATE_EXTENDED = 'rate_extended';
    public const EVENT_RATE_EXPIRED = 'rate_expired';
    public const EVENT_PAYMENT_DETECTED = 'payment_detected';
    public const EVENT_PAYMENT_CONFIRMING = 'payment_confirming';
    public const EVENT_PAYMENT_CONFIRMED = 'payment_confirmed';
    public const EVENT_PAID = 'paid';
    public const EVENT_OVERPAID = 'overpaid';
    public const EVENT_UNDERPAID = 'underpaid';
    public const EVENT_SETTLED = 'settled';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_FAILED = 'failed';
    public const EVENT_WEBHOOK_SENT = 'webhook_sent';
    public const EVENT_REFUND_INITIATED = 'refund_initiated';
    public const EVENT_REFUND_COMPLETED = 'refund_completed';

    /**
     * Event source constants
     */
    public const SOURCE_API = 'api';
    public const SOURCE_WIDGET = 'widget';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_BLOCKCHAIN = 'blockchain';

    /**
     * Actor type constants
     */
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_MERCHANT = 'merchant';
    public const ACTOR_BUYER = 'buyer';
    public const ACTOR_ADMIN = 'admin';

    /**
     * Invoice this timeline entry belongs to
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'invoice_id');
    }

    /**
     * Merchant that owns this timeline entry
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Create a timeline entry for an invoice
     */
    public static function record(
        MerchantInvoice $invoice,
        string $eventType,
        ?string $eventSource = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $eventData = null,
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $actorIp = null
    ): self {
        // Calculate duration from previous event
        $previousEvent = self::where('invoice_id', $invoice->id)
            ->orderBy('occurred_at', 'desc')
            ->first();

        $durationMs = null;
        if ($previousEvent) {
            $durationMs = (int) $previousEvent->occurred_at->diffInMilliseconds(now());
        }

        return self::create([
            'invoice_id' => $invoice->id,
            'merchant_id' => $invoice->merchant_id,
            'event_type' => $eventType,
            'event_source' => $eventSource ?? self::SOURCE_SYSTEM,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'event_data' => $eventData,
            'actor_type' => $actorType ?? self::ACTOR_SYSTEM,
            'actor_id' => $actorId,
            'actor_ip' => $actorIp,
            'occurred_at' => now(),
            'duration_from_previous_ms' => $durationMs,
        ]);
    }
}
