<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Scopes;

trait PeerOrderAppealScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('id', 'like', '%'.$search.'%');
            });
        });
    }

    public function scopeActive($query)
    {
        return $query->whereStatus(true);
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

