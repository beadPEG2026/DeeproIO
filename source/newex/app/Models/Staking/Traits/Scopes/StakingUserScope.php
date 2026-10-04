<?php

namespace App\Models\Staking\Traits\Scopes;

trait StakingUserScope
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRedeemed($query)
    {
        return $query->where('status', 'redeemed');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['referrer'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', $search);
            });
        });
    }
}
