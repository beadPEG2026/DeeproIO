<?php

namespace App\Modules\Merchant\Models;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MerchantPayout extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'merchant_payouts';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'amount_usd',
        'fee_usd',
        'net_amount_usd',
        'currency_id',
        'network_id',
        'amount_crypto',
        'rate_usd',
        'payout_address',
        'payout_memo',
        'status',
        'rejection_reason',
        'txn_hash',
        'explorer_url',
        'requested_by',
        'processed_by',
        'requested_at',
        'approved_at',
        'processed_at',
        'completed_at',
        'rejected_at',
        'merchant_notes',
        'admin_notes',
        'reference',
        'error_message',
        'error_code',
        'retry_count',
        'last_retry_at',
        'failed_at',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'fee_usd' => 'decimal:2',
        'net_amount_usd' => 'decimal:2',
        'amount_crypto' => 'decimal:18',
        'rate_usd' => 'decimal:12',
        'auto_payout_enabled' => 'boolean',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payout) {
            if (empty($payout->reference)) {
                $payout->reference = 'PO-' . strtoupper(Str::random(8));
            }
            if (empty($payout->requested_at)) {
                $payout->requested_at = now();
            }
        });
    }

    /**
     * Relationships
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Status checks
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function canBeCancelled(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeRetried(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Status transitions
     */
    public function approve(int $adminId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_at' => now(),
            'processed_by' => $adminId,
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Reject the payout - MUST be called within DB::transaction with merchant locked
     */
    public function reject(int $adminId, string $reason, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'processed_by' => $adminId,
            'admin_notes' => $notes,
        ]);

        // Return funds to merchant balance using precision math
        $merchant = $this->merchant()->lockForUpdate()->first();
        $merchant->releasePayoutReservation((string) $this->amount_usd);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processed_at' => now(),
        ]);
    }

    /**
     * Complete the payout - MUST be called within DB::transaction with merchant locked
     */
    public function complete(string $txnHash, ?string $explorerUrl = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'txn_hash' => $txnHash,
            'explorer_url' => $explorerUrl,
        ]);

        // Update merchant totals using precision math
        $merchant = $this->merchant()->lockForUpdate()->first();
        $merchant->completePayoutBalance((string) $this->amount_usd, (string) $this->net_amount_usd);
    }

    /**
     * Cancel the payout - MUST be called within DB::transaction with merchant locked
     */
    public function cancel(): void
    {
        if (!$this->canBeCancelled()) {
            throw new \RuntimeException('Payout cannot be cancelled in current status');
        }

        $this->update([
            'status' => self::STATUS_CANCELLED,
        ]);

        // Return funds to merchant balance using precision math
        $merchant = $this->merchant()->lockForUpdate()->first();
        $merchant->releasePayoutReservation((string) $this->amount_usd);
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Get status badge color
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'yellow',
            self::STATUS_APPROVED => 'blue',
            self::STATUS_PROCESSING => 'indigo',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_REJECTED => 'red',
            self::STATUS_CANCELLED => 'gray',
            self::STATUS_FAILED => 'orange',
            default => 'gray',
        };
    }

    /**
     * Mark as failed
     */
    public function markFailed(string $errorCode, string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'failed_at' => now(),
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);
    }

    /**
     * Scope for failed payouts
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
