<?php

namespace App\Models\Launchpad\Traits\Scopes;

trait LaunchpadTransactionScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', 'like', '%'.$search.'%');
            });
        })->when($filters['referrer'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', 'like', '%'.$search.'%');
            });
        })->when($filters['referrer'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', $search);
            });
        });
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeCredited($query)
    {
        return $query->where('is_credited', true);
    }

    public function scopeUncredited($query)
    {
        return $query->where('is_credited', false);
    }
}

