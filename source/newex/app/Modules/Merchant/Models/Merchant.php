<?php

namespace App\Modules\Merchant\Models;

use App\Models\User\User;
use App\Modules\Merchant\Models\Traits\Relations\MerchantRelation;
use App\Modules\Merchant\Models\Traits\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory, HasUuids, SoftDeletes, MerchantRelation, MerchantScope;

    protected $table = 'merchants';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'business_name',
        'business_type',
        'business_email',
        'business_website',
        'business_description',
        'contact_name',
        'contact_phone',
        'country_code',
        'status',
        'verification_status',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'rejected_at',
        'submission_count',
        'last_submitted_at',
        'default_webhook_url',
        'webhook_secret',
        'webhook_events',
        'ip_whitelist',
        'settlement_currency',
        'settlement_address',
        'settlement_network_id',
        'daily_volume_limit_usd',
        'monthly_volume_limit_usd',
        'single_invoice_limit_usd',
        'min_invoice_amount_usd',
        'fee_percent',
        'fee_fixed_usd',
        'total_invoices',
        'paid_invoices',
        'total_volume_usd',
        'total_fees_usd',
        'available_balance_usd',
        'pending_balance_usd',
        'total_paid_out_usd',
        'default_payout_address',
        'default_payout_memo',
        'min_payout_amount_usd',
        'auto_payout_enabled',
        'auto_payout_threshold_usd',
        'last_invoice_at',
        'risk_score',
        'risk_notes',
        'manual_review_required',
        'metadata',
        'admin_notes',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'last_submitted_at' => 'datetime',
        'last_invoice_at' => 'datetime',
        'webhook_events' => 'array',
        'ip_whitelist' => 'array',
        'metadata' => 'array',
        'daily_volume_limit_usd' => 'decimal:2',
        'monthly_volume_limit_usd' => 'decimal:2',
        'single_invoice_limit_usd' => 'decimal:2',
        'min_invoice_amount_usd' => 'decimal:2',
        'fee_percent' => 'decimal:4',
        'fee_fixed_usd' => 'decimal:2',
        'total_volume_usd' => 'decimal:2',
        'total_fees_usd' => 'decimal:2',
        'available_balance_usd' => 'decimal:2',
        'pending_balance_usd' => 'decimal:2',
        'total_paid_out_usd' => 'decimal:2',
        'min_payout_amount_usd' => 'decimal:2',
        'auto_payout_enabled' => 'boolean',
        'auto_payout_threshold_usd' => 'decimal:2',
        'manual_review_required' => 'boolean',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_TERMINATED = 'terminated';

    /**
     * Verification status constants
     */
    public const VERIFICATION_UNVERIFIED = 'unverified';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REJECTED = 'rejected';

    /**
     * Check if merchant is active and can create invoices
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if merchant is verified
     */
    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    /**
     * Check if merchant application is rejected
     */
    public function isRejected(): bool
    {
        return $this->verification_status === self::VERIFICATION_REJECTED;
    }

    /**
     * Check if merchant application is pending
     */
    public function isPending(): bool
    {
        return $this->verification_status === self::VERIFICATION_PENDING 
            || $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if merchant can resubmit application
     */
    public function canResubmit(): bool
    {
        return $this->isRejected();
    }

    /**
     * Check if merchant is suspended
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Check if merchant can operate (create invoices, API keys, request payouts)
     */
    public function canOperate(): bool
    {
        return $this->isActive() && $this->isVerified();
    }

    /**
     * Get the restriction reason if merchant cannot operate
     */
    public function getOperationRestriction(): ?array
    {
        if ($this->canOperate()) {
            return null;
        }

        if ($this->isSuspended()) {
            return [
                'type' => 'suspended',
                'title' => 'Account Suspended',
                'message' => 'Your merchant account has been suspended. Please contact support for more information.',
            ];
        }

        if ($this->isPending()) {
            return [
                'type' => 'pending',
                'title' => 'Verification Pending',
                'message' => 'Your merchant account is pending verification. You will be able to operate once your account is verified.',
            ];
        }

        if ($this->isRejected()) {
            return [
                'type' => 'rejected',
                'title' => 'Application Rejected',
                'message' => 'Your merchant application was rejected. Please resubmit your application.',
            ];
        }

        if (!$this->isVerified()) {
            return [
                'type' => 'unverified',
                'title' => 'Verification Required',
                'message' => 'Your merchant account needs to be verified before you can operate.',
            ];
        }

        return [
            'type' => 'inactive',
            'title' => 'Account Inactive',
            'message' => 'Your merchant account is not active. Please contact support.',
        ];
    }

    /**
     * Resubmit the application for review
     */
    public function resubmit(array $data): void
    {
        $this->update(array_merge($data, [
            'status' => self::STATUS_PENDING,
            'verification_status' => self::VERIFICATION_PENDING,
            'rejection_reason' => null,
            'rejected_at' => null,
            'submission_count' => $this->submission_count + 1,
            'last_submitted_at' => now(),
        ]));
    }

    /**
     * Generate a new webhook secret
     */
    public function generateWebhookSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->webhook_secret = $secret;
        $this->save();
        
        return $secret;
    }

    /**
     * Check if IP is in whitelist (empty whitelist = all allowed)
     */
    public function isIpAllowed(?string $ip): bool
    {
        if (empty($this->ip_whitelist)) {
            return true;
        }

        return in_array($ip, $this->ip_whitelist);
    }

    /**
     * Increment invoice statistics (call within a transaction with lockForUpdate)
     */
    public function incrementInvoiceStats(string $amountUsd, string $feeUsd): void
    {
        $this->total_invoices = ($this->total_invoices ?? 0) + 1;
        $this->paid_invoices = ($this->paid_invoices ?? 0) + 1;
        $this->total_volume_usd = math_sum($this->total_volume_usd ?? '0', $amountUsd);
        $this->total_fees_usd = math_sum($this->total_fees_usd ?? '0', $feeUsd);
        $this->last_invoice_at = now();
        $this->save();
    }

    /**
     * Add earnings to available balance (call within a transaction with lockForUpdate)
     */
    public function addEarnings(string $netAmountUsd): void
    {
        $this->available_balance_usd = math_sum($this->available_balance_usd ?? '0', $netAmountUsd);
        $this->save();
    }

    /**
     * Get total balance (available + pending)
     */
    public function getTotalBalance(): string
    {
        return math_sum($this->available_balance_usd ?? '0', $this->pending_balance_usd ?? '0');
    }

    /**
     * Check if merchant can request payout
     */
    public function canRequestPayout(string $amount): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if (math_compare($amount, $this->available_balance_usd ?? '0') > 0) {
            return false;
        }

        if (math_compare($amount, $this->min_payout_amount_usd ?? '0') < 0) {
            return false;
        }

        return true;
    }

    /**
     * Reserve balance for payout (call within a transaction with lockForUpdate)
     */
    public function reserveForPayout(string $amountUsd): void
    {
        $this->available_balance_usd = math_sub($this->available_balance_usd ?? '0', $amountUsd);
        $this->pending_balance_usd = math_sum($this->pending_balance_usd ?? '0', $amountUsd);
        $this->save();
    }

    /**
     * Release reserved payout back to available balance (call within a transaction with lockForUpdate)
     */
    public function releasePayoutReservation(string $amountUsd): void
    {
        $this->available_balance_usd = math_sum($this->available_balance_usd ?? '0', $amountUsd);
        $this->pending_balance_usd = math_sub($this->pending_balance_usd ?? '0', $amountUsd);
        $this->save();
    }

    /**
     * Complete payout - deduct from pending and add to paid out (call within a transaction with lockForUpdate)
     */
    public function completePayoutBalance(string $grossAmount, string $netAmount): void
    {
        $this->pending_balance_usd = math_sub($this->pending_balance_usd ?? '0', $grossAmount);
        $this->total_paid_out_usd = math_sum($this->total_paid_out_usd ?? '0', $netAmount);
        $this->save();
    }

    /**
     * Payouts relationship
     */
    public function payouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MerchantPayout::class, 'merchant_id');
    }

    /**
     * Get net earnings (volume - fees)
     */
    public function getNetEarnings(): float
    {
        return (float) $this->total_volume_usd - (float) $this->total_fees_usd;
    }
}
