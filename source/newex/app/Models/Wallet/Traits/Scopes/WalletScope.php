<?php

namespace App\Models\Wallet\Traits\Scopes;

trait WalletScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('id', 'like', '%'.$search.'%');
                $query->orWhereHas('user', function ($query) use ($search) {
                    return $query->where('email', '=', $search);
                })->get();
                $query->orWhereHas('address', function ($query) use ($search) {
                    return $query->where('address', '=', $search);
                })->get();
                $query->orWhereHas('currency', function ($query) use ($search) {
                    return $query->where('symbol', '=', $search);
                })->get();
            });
        })->when($filters['type'] ?? null, function ($query, $type) {
            if($type == "balance") {
                $query->where(function ($query) {
                    $query->where('balance_in_wallet', '>', 0);
                    $query->orWhere('balance_in_order', '>', 0);
                    $query->orWhere('balance_in_withdraw', '>', 0);
                });
            }
        })->when($filters['referrer'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', $search);
            });
        })->when($filters['user'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', $search);
            });
        });
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

