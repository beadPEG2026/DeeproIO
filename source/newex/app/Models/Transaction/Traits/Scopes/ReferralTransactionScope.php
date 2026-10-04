<?php

namespace App\Models\Transaction\Traits\Scopes;

trait ReferralTransactionScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', 'like', '%'.$search.'%');
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

    public function scopePending($query)
    {
        return $query->where('is_credited', false);
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

