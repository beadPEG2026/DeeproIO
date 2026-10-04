<?php

namespace App\Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class MerchantWebhookDeadLetter extends Model
{
    use HasFactory;

    protected $table = 'merchant_webhook_dead_letters';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending_resolution';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'webhook_id',
        'merchant_id',
        'invoice_id',
        'event_type',
        'priority',
        'payload',
        'total_attempts',
        'first_attempt_at',
        'last_attempt_at',
        'last_response_code',
        'last_error',
        'status',
        'resolution_action',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'first_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the merchant
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the invoice
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'invoice_id');
    }

    /**
     * Get the original webhook
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(MerchantWebhook::class, 'webhook_id');
    }

    /**
     * Scope to unresolved dead letters
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope to resolved dead letters
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope by merchant
     */
    public function scopeForMerchant(Builder $query, string $merchantId): Builder
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Mark as resolved
     */
    public function resolve(string $action, ?int $resolvedBy = null, ?string $notes = null): void
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolution_action = $action;
        $this->resolved_at = now();
        $this->resolved_by = $resolvedBy;
        $this->resolution_notes = $notes;
        $this->save();
    }

    /**
     * Mark as ignored
     */
    public function ignore(?int $resolvedBy = null, ?string $notes = null): void
    {
        $this->status = self::STATUS_IGNORED;
        $this->resolution_action = 'ignored';
        $this->resolved_at = now();
        $this->resolved_by = $resolvedBy;
        $this->resolution_notes = $notes;
        $this->save();
    }

    /**
     * Check if resolved
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED || $this->status === self::STATUS_IGNORED;
    }

    /**
     * Scope by status
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
