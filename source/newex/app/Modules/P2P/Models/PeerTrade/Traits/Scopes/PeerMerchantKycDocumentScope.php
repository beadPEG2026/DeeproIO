<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Scopes;

trait PeerMerchantKycDocumentScope
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
        })->whereHas('user', function ($query) {
            return $query->where('deleted', false);
        })->get();
    }
}

