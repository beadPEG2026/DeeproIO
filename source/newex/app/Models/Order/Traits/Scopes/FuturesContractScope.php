<?php

namespace App\Models\Order\Traits\Scopes;

use App\Models\Order\Order;

trait FuturesContractScope
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFilterUser($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {

            });
        })->when($filters['market'] ?? null, function ($query, $market) {
            $query->where('market_id', $market);
        })->when($filters['type'] ?? null, function ($query, $type) {
            $query->where('is_long', $type == "long");
        });
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
