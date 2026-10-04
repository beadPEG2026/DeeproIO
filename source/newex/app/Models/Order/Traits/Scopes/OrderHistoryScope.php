<?php

namespace App\Models\Order\Traits\Scopes;

trait OrderHistoryScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('user', function ($query) use ($search) {
                    return $query->where('email', '=', $search);
                })->get();
            });
        });
    }

    public function scopeFilterUser($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {

            });
        })->when($filters['market'] ?? null, function ($query, $market) {
            $query->where('market_id', $market);
        })->when($filters['side'] ?? null, function ($query, $side) {
            $query->where('side', $side);
        });
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', '!=', ORDER_STATUS_ACTIVE);
    }
}

