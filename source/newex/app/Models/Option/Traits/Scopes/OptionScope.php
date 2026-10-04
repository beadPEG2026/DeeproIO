<?php

namespace App\Models\Option\Traits\Scopes;

trait OptionScope
{
    public function scopeProcessable($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeProcessed($query)
    {
        return $query->whereNotIn('status', ['active','scheduled','review_required']);
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere('user_id', 'like', '%'.$search.'%');
                $query->orWhereHas('user', function ($query) use ($search) {
                    return $query->where('email', '=', $search);
                })->get();
            });
        })->when($filters['referrer'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', $search);
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
            $query->where('type', $side);
        });
    }
}
