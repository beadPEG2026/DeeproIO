<?php

namespace App\Models\Lending\Traits\Scopes;

trait LendingScope
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
        return $query->where('status', 'sold')->orWhere('status', 'active');
    }
}
