<?php

namespace App\Modules\Merchant\Models;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Modules\Merchant\Models\Traits\Relations\MerchantInvoicePaymentRelation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantInvoicePayment extends Model
{
    use HasFactory, HasUuids, MerchantInvoicePaymentRelation;

    protected $table = 'merchant_invoice_payments';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'invoice_id',
        'merchant_id',
        'currency_id',
        'deposit_address_id',
        'txn_hash',
        'block_number',
        'block_index',
        'from_address',
        'to_address',
        'memo',
        'amount_crypto',
        'amount_usd',
        'rate_usd_at_detection',
        'confirmations',
        'required_confirmations',
        'status',
        'status_reason',
        'classification',
        'is_late_payment',
        'counted_in_total',
        'detected_at',
        'first_confirmation_at',
        'confirmed_at',
        'failed_at',
        'collected_at',
        'collection_txn_hash',
        'detection_source',
        'detection_node',
        'raw_transaction',
        'block_data',
        'explorer_url',
    ];

    protected $casts = [
        'amount_crypto' => CryptoCurrencyDecimalCast::class,
        'amount_usd' => 'decimal:2',
        'rate_usd_at_detection' => 'decimal:12',
        'is_late_payment' => 'boolean',
        'counted_in_total' => 'boolean',
        'detected_at' => 'datetime',
        'first_confirmation_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
        'collected_at' => 'datetime',
        'raw_transaction' => 'array',
        'block_data' => 'array',
    ];

    /**
     * Status constants
     */
    public const STATUS_DETECTING = 'detecting';
    public const STATUS_CONFIRMING = 'confirming';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ORPHANED = 'orphaned';

    /**
     * Classification constants
     */
    public const CLASSIFICATION_EXACT = 'exact';
    public const CLASSIFICATION_PARTIAL = 'partial';
    public const CLASSIFICATION_OVERPAYMENT = 'overpayment';
    public const CLASSIFICATION_LATE = 'late';

    /**
     * Detection source constants
     */
    public const SOURCE_MEMPOOL = 'mempool';
    public const SOURCE_BLOCK = 'block';
    public const SOURCE_MANUAL = 'manual';

    /**
     * Check if payment is confirmed
     */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Check if payment has enough confirmations
     */
    public function hasEnoughConfirmations(): bool
    {
        return $this->confirmations >= $this->required_confirmations;
    }

    /**
     * Get confirmation progress percentage
     */
    public function getConfirmationProgressPercent(): float
    {
        if ($this->required_confirmations == 0) {
            return 100;
        }

        return min(100, ($this->confirmations / $this->required_confirmations) * 100);
    }

    /**
     * Update confirmation count
     */
    public function updateConfirmations(int $confirmations): void
    {
        $this->confirmations = $confirmations;

        if ($this->confirmations >= 1 && $this->first_confirmation_at === null) {
            $this->first_confirmation_at = now();
        }

        if ($this->hasEnoughConfirmations() && $this->status === self::STATUS_CONFIRMING) {
            $this->status = self::STATUS_CONFIRMED;
            $this->confirmed_at = now();
        }

        $this->save();
    }

    /**
     * Get short transaction hash for display
     */
    public function getShortTxnHash(): string
    {
        if (strlen($this->txn_hash) <= 16) {
            return $this->txn_hash;
        }

        return substr($this->txn_hash, 0, 8) . '...' . substr($this->txn_hash, -8);
    }
}
