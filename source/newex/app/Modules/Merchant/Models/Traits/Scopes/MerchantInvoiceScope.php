<?php

namespace App\Modules\Merchant\Models\Traits\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait MerchantInvoiceScope
{
    /**
     * Scope to invoices for a specific merchant
     */
    public function scopeForMerchant(Builder $query, string $merchantId): Builder
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Scope by status
     */
    public function scopeByStatus(Builder $query, string|array $status): Builder
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }
        return $query->where('status', $status);
    }

    /**
     * Scope to pending invoices (awaiting payment)
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            'awaiting_selection',
            'awaiting_payment',
            'detecting',
            'confirming',
        ]);
    }

    /**
     * Scope to paid invoices
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereIn('status', ['paid', 'overpaid', 'settled']);
    }

    /**
     * Scope to completed invoices (final state)
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            'paid', 'overpaid', 'settled', 'expired', 'cancelled', 'failed', 'refunded'
        ]);
    }

    /**
     * Scope to expired invoices
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }

    /**
     * Scope to invoices expiring soon
     */
    public function scopeExpiringSoon(Builder $query, int $minutes = 5): Builder
    {
        return $query->whereIn('status', ['awaiting_selection', 'awaiting_payment'])
            ->where('expires_at', '<=', now()->addMinutes($minutes))
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to invoices that should be expired
     */
    public function scopeShouldExpire(Builder $query): Builder
    {
        return $query->whereIn('status', ['awaiting_selection', 'awaiting_payment', 'detecting'])
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope by external ID
     */
    public function scopeByExternalId(Builder $query, string $externalId): Builder
    {
        return $query->where('external_id', $externalId);
    }

    /**
     * Scope by environment
     */
    public function scopeByEnvironment(Builder $query, string $environment): Builder
    {
        return $query->where('environment', $environment);
    }

    /**
     * Scope to live invoices
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('environment', 'live');
    }

    /**
     * Scope to sandbox invoices
     */
    public function scopeSandbox(Builder $query): Builder
    {
        return $query->where('environment', 'sandbox');
    }

    /**
     * Scope by date range
     */
    public function scopeCreatedBetween(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to invoices with rate expiring soon
     */
    public function scopeRateExpiringSoon(Builder $query, int $minutes = 2): Builder
    {
        return $query->where('status', 'awaiting_payment')
            ->where('rate_expires_at', '<=', now()->addMinutes($minutes))
            ->where('rate_expires_at', '>', now());
    }

    /**
     * Scope to invoices with expired rates
     */
    public function scopeRateExpired(Builder $query): Builder
    {
        return $query->where('status', 'awaiting_payment')
            ->where('rate_expires_at', '<=', now());
    }

    /**
     * Scope by amount range
     */
    public function scopeAmountBetween(Builder $query, float $minUsd, float $maxUsd): Builder
    {
        return $query->where('amount_usd', '>=', $minUsd)
            ->where('amount_usd', '<=', $maxUsd);
    }

    /**
     * Order by newest first
     */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope invoices that need confirmation check
     */
    public function scopeNeedsConfirmationCheck(Builder $query): Builder
    {
        return $query->whereIn('status', ['detecting', 'confirming'])
            ->whereHas('payments', function ($q) {
                $q->whereIn('status', ['detecting', 'confirming']);
            });
    }
}
