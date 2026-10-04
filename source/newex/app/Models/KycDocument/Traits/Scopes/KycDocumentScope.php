<?php

namespace App\Models\KycDocument\Traits\Scopes;

trait KycDocumentScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', 'like', '%'.$search.'%');
                $query->orWhere('status', 'like', '%'.$search.'%');
                $query->orWhere('first_name', 'like', '%'.$search.'%');
                $query->orWhere('last_name', 'like', '%'.$search.'%');
                $query->orWhere('document_number', 'like', '%'.$search.'%');
                $query->orWhereHas('user', function ($query) use ($search) {
                    return $query->where('email', 'like', '%'.$search.'%')
                        ->orWhere('referral_code', 'like', '%'.$search.'%')
                        ->orWhere('wallet_id', 'like', '%'.$search.'%');
                });
            });
        })->whereHas('user', function ($query) {
            return $query->where('deleted', false);
        });
    }
}
