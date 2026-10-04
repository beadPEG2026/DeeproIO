<?php

namespace App\Modules\Merchant\Models\Traits\Scopes;

use Illuminate\Database\Eloquent\Builder;

trait MerchantScope
{
    /**
     * Scope to active merchants
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to verified merchants
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Scope to pending verification
     */
    public function scopePendingVerification(Builder $query): Builder
    {
        return $query->where('verification_status', 'pending');
    }

    /**
     * Scope by status
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to merchants requiring manual review
     */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('manual_review_required', true);
    }

    /**
     * Scope to merchants with high risk score
     */
    public function scopeHighRisk(Builder $query, int $threshold = 70): Builder
    {
        return $query->where('risk_score', '>=', $threshold);
    }

    /**
     * Scope to merchants by user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to merchants with volume in date range
     */
    public function scopeWithVolumeInRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereHas('invoices', function ($q) use ($startDate, $endDate) {
            $q->whereIn('status', ['paid', 'settled', 'overpaid'])
                ->whereBetween('paid_at', [$startDate, $endDate]);
        });
    }
}
