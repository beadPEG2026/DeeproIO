<?php

namespace App\Modules\Merchant\Models\Traits\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait MerchantWebhookScope
{
    /**
     * Scope to webhooks for a specific merchant
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
     * Scope to pending webhooks
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to webhooks ready for retry
     */
    public function scopeReadyForRetry(Builder $query): Builder
    {
        return $query->where('status', 'pending_retry')
            ->where('next_retry_at', '<=', now())
            ->where('circuit_breaker_active', false);
    }

    /**
     * Scope to failed webhooks
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to delivered webhooks
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', 'delivered');
    }

    /**
     * Scope by event type
     */
    public function scopeByEventType(Builder $query, string|array $eventType): Builder
    {
        if (is_array($eventType)) {
            return $query->whereIn('event_type', $eventType);
        }
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope by priority
     */
    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to critical webhooks
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('priority', 'critical');
    }

    /**
     * Scope to webhooks that need processing
     */
    public function scopeNeedsProcessing(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', 'pending')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'pending_retry')
                        ->where('next_retry_at', '<=', now());
                });
        })->where('circuit_breaker_active', false);
    }

    /**
     * Order by priority and creation time
     */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query->orderByRaw("CASE priority 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'normal' THEN 3 
            WHEN 'low' THEN 4 
            ELSE 5 END")
            ->orderBy('created_at', 'asc');
    }

    /**
     * Scope by invoice
     */
    public function scopeForInvoice(Builder $query, string $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }

    /**
     * Scope to webhooks with circuit breaker active
     */
    public function scopeCircuitBreakerActive(Builder $query): Builder
    {
        return $query->where('circuit_breaker_active', true);
    }

    /**
     * Scope to webhooks created in date range
     */
    public function scopeCreatedBetween(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}
