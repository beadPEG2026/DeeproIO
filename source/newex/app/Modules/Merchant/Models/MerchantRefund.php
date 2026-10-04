<?php

namespace App\Modules\Merchant\Models;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Modules\Merchant\Models\Traits\Relations\MerchantRefundRelation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantRefund extends Model
{
    use HasFactory, HasUuids, MerchantRefundRelation;

    protected $table = 'merchant_refunds';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'invoice_id',
        'payment_id',
        'orphan_payment_id',
        'refund_type',
        'reason',
        'reason_details',
        'amount_crypto',
        'amount_usd',
        'rate_usd',
        'destination_address',
        'destination_memo',
        'destination_network_id',
        'status',
        'txn_hash',
        'confirmations',
        'network_fee_crypto',
        'network_fee_usd',
        'requested_at',
        'approved_at',
        'broadcast_at',
        'confirmed_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'requested_by',
        'approved_by',
        'auto_approved',
        'failure_reason',
        'retry_count',
        'admin_notes',
        'metadata',
    ];

    protected $casts = [
        'amount_crypto' => CryptoCurrencyDecimalCast::class,
        'amount_usd' => 'decimal:2',
        'rate_usd' => 'decimal:12',
        'network_fee_crypto' => CryptoCurrencyDecimalCast::class,
        'network_fee_usd' => 'decimal:2',
        'auto_approved' => 'boolean',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'broadcast_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_BROADCAST = 'broadcast';
    public const STATUS_CONFIRMING = 'confirming';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Refund type constants
     */
    public const TYPE_FULL = 'full';
    public const TYPE_PARTIAL = 'partial';
    public const TYPE_OVERPAYMENT = 'overpayment';
    public const TYPE_LATE_PAYMENT = 'late_payment';

    /**
     * Reason constants
     */
    public const REASON_MERCHANT_REQUEST = 'merchant_request';
    public const REASON_OVERPAYMENT = 'overpayment';
    public const REASON_LATE_PAYMENT = 'late_payment';
    public const REASON_DUPLICATE = 'duplicate';
    public const REASON_SYSTEM_ERROR = 'system_error';

    /**
     * Check if refund is in a final state
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Check if refund needs approval
     */
    public function needsApproval(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->auto_approved;
    }

    /**
     * Approve refund
     */
    public function approve(?int $userId = null): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->approved_at = now();
        $this->approved_by = $userId;
        $this->save();
    }

    /**
     * Mark as broadcast
     */
    public function markBroadcast(string $txnHash): void
    {
        $this->status = self::STATUS_BROADCAST;
        $this->txn_hash = $txnHash;
        $this->broadcast_at = now();
        $this->save();
    }

    /**
     * Mark as completed
     */
    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        $this->confirmed_at = now();
        $this->save();
    }

    /**
     * Mark as failed
     */
    public function markFailed(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failed_at = now();
        $this->failure_reason = $reason;
        $this->retry_count++;
        $this->save();
    }

    /**
     * Cancel refund
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->save();
    }
}
