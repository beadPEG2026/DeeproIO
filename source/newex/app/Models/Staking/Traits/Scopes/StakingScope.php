<?php

namespace App\Models\Staking\Traits\Scopes;

trait StakingScope
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSold($query)
    {
        return $query->where('status', 'sold');
    }

    public function scopeVisible($query)
    {
        return $query->whereIn('status', ['sold','active']);
    }
}
