<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Scopes;


trait PeerTradePaymentFieldScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%');
            });
        });
    }

    public function scopeRequired($query)
    {
        return $query->whereRequired(true);
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeOrderByAsc($query)
    {
        return $query->orderBy('id');
    }
}

