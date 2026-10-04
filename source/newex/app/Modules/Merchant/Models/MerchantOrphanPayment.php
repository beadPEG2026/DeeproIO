<?php

namespace App\Modules\Merchant\Models;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Modules\Merchant\Models\Traits\Relations\MerchantOrphanPaymentRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantOrphanPayment extends Model
{
    use HasFactory, HasUuids, MerchantOrphanPaymentRelation;

    protected $table = 'merchant_orphan_payments';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'address_id',
        'currency_id',
        'txn_hash',
        'block_number',
        'from_address',
        'to_address',
        'memo',
        'amount_crypto',
        'amount_usd',
        'rate_usd_at_detection',
        'orphan_reason',
        'related_invoice_id',
        'status',
        'resolution_type',
        'resolution_invoice_id',
        'resolution_txn_hash',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'detected_at',
        'confirmations',
        'confirmed_at',
        'explorer_url',
    ];

    protected $casts = [
        'amount_crypto' => CryptoCurrencyDecimalCast::class,
        'amount_usd' => 'decimal:2',
        'rate_usd_at_detection' => 'decimal:12',
        'detected_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_SWEPT = 'swept';

    /**
     * Orphan reason constants
     */
    public const REASON_NO_MATCHING_INVOICE = 'no_matching_invoice';
    public const REASON_INVOICE_EXPIRED = 'invoice_expired';
    public const REASON_ADDRESS_NOT_ASSIGNED = 'address_not_assigned';
    public const REASON_DUPLICATE_PAYMENT = 'duplicate_payment';

    /**
     * Resolution type constants
     */
    public const RESOLUTION_REFUND = 'refund';
    public const RESOLUTION_CREDIT = 'credit';
    public const RESOLUTION_SWEEP = 'sweep';

    /**
     * Check if orphan payment can be resolved
     */
    public function canBeResolved(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Resolve via refund
     */
    public function resolveAsRefund(string $txnHash, ?int $userId = null, ?string $notes = null): void
    {
        $this->status = self::STATUS_REFUNDED;
        $this->resolution_type = self::RESOLUTION_REFUND;
        $this->resolution_txn_hash = $txnHash;
        $this->resolution_notes = $notes;
        $this->resolved_by = $userId;
        $this->resolved_at = now();
        $this->save();
    }

    /**
     * Resolve via credit to merchant balance or new invoice
     */
    public function resolveAsCredit(?string $invoiceId = null, ?int $userId = null, ?string $notes = null): void
    {
        $this->status = self::STATUS_CLAIMED;
        $this->resolution_type = self::RESOLUTION_CREDIT;
        $this->resolution_invoice_id = $invoiceId;
        $this->resolution_notes = $notes;
        $this->resolved_by = $userId;
        $this->resolved_at = now();
        $this->save();
    }

    /**
     * Resolve via sweep (consolidated to main wallet)
     */
    public function resolveAsSweep(string $txnHash, ?int $userId = null, ?string $notes = null): void
    {
        $this->status = self::STATUS_SWEPT;
        $this->resolution_type = self::RESOLUTION_SWEEP;
        $this->resolution_txn_hash = $txnHash;
        $this->resolution_notes = $notes;
        $this->resolved_by = $userId;
        $this->resolved_at = now();
        $this->save();
    }

    /**
     * Scope to unresolved orphan payments
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to resolved orphan payments
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_PENDING);
    }

    /**
     * Scope by merchant
     */
    public function scopeForMerchant(Builder $query, string $merchantId): Builder
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Scope by reason
     */
    public function scopeByReason(Builder $query, string $reason): Builder
    {
        return $query->where('orphan_reason', $reason);
    }
}
