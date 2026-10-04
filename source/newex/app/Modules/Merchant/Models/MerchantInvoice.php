<?php

namespace App\Modules\Merchant\Models;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Modules\Merchant\Models\Traits\Relations\MerchantInvoiceRelation;
use App\Modules\Merchant\Models\Traits\Scopes\MerchantInvoiceScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantInvoice extends Model
{
    use HasFactory, HasUuids, SoftDeletes, MerchantInvoiceRelation, MerchantInvoiceScope;

    protected $table = 'merchant_invoices';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'currency_id',
        'network_id',
        'external_id',
        'idempotency_key',
        'status',
        'previous_status',
        'status_reason',
        'amount_usd',
        'currency',
        'amount_crypto',
        'amount_crypto_min',
        'amount_crypto_max',
        'amount_received_crypto',
        'amount_received_usd',
        'rate_usd',
        'rate_bid',
        'rate_ask',
        'rate_source',
        'rate_spread_applied',
        'rate_locked_at',
        'rate_expires_at',
        'rate_extended_count',
        'rate_extended_at',
        'deposit_address',
        'deposit_memo',
        'deposit_address_id',
        'customer_email',
        'customer_name',
        'customer_metadata',
        'description',
        'metadata',
        'line_items',
        'redirect_url',
        'cancel_url',
        'webhook_url',
        'fee_percent',
        'fee_amount_crypto',
        'fee_amount_usd',
        'net_amount_crypto',
        'net_amount_usd',
        'payment_classification',
        'payment_variance_percent',
        'expires_at',
        'selection_expires_at',
        'payment_expires_at',
        'currency_selected_at',
        'first_payment_at',
        'paid_at',
        'settled_at',
        'expired_at',
        'cancelled_at',
        'failed_at',
        'cancellation_reason',
        'cancelled_by',
        'source',
        'source_ip',
        'user_agent',
        'environment',
    ];

    protected $casts = [
        'amount_usd' => 'decimal:2',
        'amount_crypto' => CryptoCurrencyDecimalCast::class,
        'amount_crypto_min' => CryptoCurrencyDecimalCast::class,
        'amount_crypto_max' => CryptoCurrencyDecimalCast::class,
        'amount_received_crypto' => CryptoCurrencyDecimalCast::class,
        'amount_received_usd' => 'decimal:2',
        'rate_usd' => 'decimal:12',
        'rate_bid' => 'decimal:12',
        'rate_ask' => 'decimal:12',
        'rate_spread_applied' => 'decimal:4',
        'fee_percent' => 'decimal:4',
        'fee_amount_crypto' => CryptoCurrencyDecimalCast::class,
        'fee_amount_usd' => 'decimal:2',
        'net_amount_crypto' => CryptoCurrencyDecimalCast::class,
        'net_amount_usd' => 'decimal:2',
        'payment_variance_percent' => 'decimal:4',
        'customer_metadata' => 'array',
        'metadata' => 'array',
        'line_items' => 'array',
        'rate_locked_at' => 'datetime',
        'rate_expires_at' => 'datetime',
        'rate_extended_at' => 'datetime',
        'expires_at' => 'datetime',
        'selection_expires_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'currency_selected_at' => 'datetime',
        'first_payment_at' => 'datetime',
        'paid_at' => 'datetime',
        'settled_at' => 'datetime',
        'expired_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Invoice status constants
     */
    public const STATUS_AWAITING_SELECTION = 'awaiting_selection';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_DETECTING = 'detecting';
    public const STATUS_CONFIRMING = 'confirming';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERPAID = 'overpaid';
    public const STATUS_UNDERPAID = 'underpaid';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDING = 'refunding';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Payment classification constants
     */
    public const CLASSIFICATION_EXACT = 'exact';
    public const CLASSIFICATION_UNDERPAID = 'underpaid';
    public const CLASSIFICATION_OVERPAID = 'overpaid';

    /**
     * Source constants
     */
    public const SOURCE_API = 'api';
    public const SOURCE_WIDGET = 'widget';
    public const SOURCE_DASHBOARD = 'dashboard';

    /**
     * Environment constants
     */
    public const ENV_LIVE = 'live';
    public const ENV_SANDBOX = 'sandbox';

    /**
     * Check if invoice can accept payments
     */
    public function canAcceptPayment(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_PAYMENT,
            self::STATUS_DETECTING,
            self::STATUS_CONFIRMING,
            self::STATUS_UNDERPAID,
        ]);
    }

    /**
     * Check if invoice is in a final state
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_OVERPAID,
            self::STATUS_SETTLED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_REFUNDED,
        ]);
    }

    /**
     * Check if invoice can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_SELECTION,
            self::STATUS_AWAITING_PAYMENT,
        ]);
    }

    /**
     * Check if currency has been selected
     */
    public function hasCurrencySelected(): bool
    {
        return $this->currency_id !== null && $this->deposit_address !== null;
    }

    /**
     * Check if rate is still valid
     */
    public function isRateValid(): bool
    {
        if ($this->rate_expires_at === null) {
            return false;
        }

        return $this->rate_expires_at->isFuture();
    }

    /**
     * Check if invoice is expired
     */
    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        // Check overall invoice expiry
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return true;
        }

        // Check payment window expiry (only if currency was selected)
        if ($this->currency_id !== null && $this->payment_expires_at !== null && $this->payment_expires_at->isPast()) {
            return true;
        }

        // Check selection window expiry (if currency not yet selected)
        if ($this->currency_id === null && $this->selection_expires_at !== null && $this->selection_expires_at->isPast()) {
            return true;
        }

        return false;
    }

    /**
     * Check if invoice is expired and update status if needed
     */
    public function checkAndExpire(): bool
    {
        // Don't expire if already in a final state
        if ($this->isFinal()) {
            return false;
        }

        if ($this->isExpired() && $this->status !== self::STATUS_EXPIRED) {
            $this->transitionTo(self::STATUS_EXPIRED, 'Payment window expired');
            $this->expired_at = now();
            $this->save();
            return true;
        }

        return false;
    }

    /**
     * Get remaining time until payment expires (in seconds)
     */
    public function getPaymentTimeRemaining(): ?int
    {
        if ($this->payment_expires_at === null) {
            return null;
        }

        $remaining = $this->payment_expires_at->diffInSeconds(now(), false);
        return max(0, -$remaining);
    }

    /**
     * Get remaining time until rate expires (in seconds)
     */
    public function getRateTimeRemaining(): ?int
    {
        if ($this->rate_expires_at === null) {
            return null;
        }

        $remaining = $this->rate_expires_at->diffInSeconds(now(), false);
        return max(0, -$remaining);
    }

    /**
     * Calculate payment progress percentage
     */
    public function getPaymentProgressPercent(): float
    {
        if ($this->amount_crypto === null || $this->amount_crypto == 0) {
            return 0;
        }

        return min(100, ($this->amount_received_crypto / $this->amount_crypto) * 100);
    }

    /**
     * Get checkout URL
     */
    public function getCheckoutUrl(): string
    {
        return config('merchant_acquiring.checkout_base_url') . '/i/' . $this->id;
    }

    /**
     * Transition to new status
     */
    public function transitionTo(string $newStatus, ?string $reason = null): bool
    {
        $this->previous_status = $this->status;
        $this->status = $newStatus;
        $this->status_reason = $reason;

        return $this->save();
    }
}
