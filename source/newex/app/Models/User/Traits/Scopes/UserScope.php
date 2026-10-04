<?php

namespace App\Models\User\Traits\Scopes;

trait UserScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $search = trim((string) $search);
                $query->where('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('referral_code', 'like', '%'.$search.'%');
                if (ctype_digit($search)) $query->orWhere('users.id', $search);
                $query->orWhereExists(function ($addresses) use ($search) {
                    $addresses->selectRaw('1')->from('wallet_addresses')
                        ->whereColumn('wallet_addresses.user_id', 'users.id')
                        ->whereNull('wallet_addresses.deleted_at')
                        ->where('wallet_addresses.address', 'like', '%'.$search.'%');
                });
            });
        });
    }

    public function scopeAuthorizable($query)
    {
        return $query->where('deleted', false);
    }
}

