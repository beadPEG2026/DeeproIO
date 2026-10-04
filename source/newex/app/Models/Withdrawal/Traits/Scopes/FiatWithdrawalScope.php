<?php

namespace App\Models\Withdrawal\Traits\Scopes;

trait FiatWithdrawalScope
{
    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('withdrawal_id', 'like', '%'.$search.'%');
                $query->orWhere('user_id', 'like', '%'.$search.'%');
                $query->orWhereHas('user', function ($query) use ($search) {
                    return $query->where('email', '=', $search);
                })->get();
            });
        })->when($filters['referrer'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('user_id', $search);
            });
        });
    }

    public function scopeFilterUser($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {

            });
        })->when($filters['currency'] ?? null, function ($query, $currency) {
            $query->where('currency_id', $currency);
        })->when($filters['status'] ?? null, function ($query, $status) {

            if($status == "completed")
                $status = "confirmed";

            $query->where('status', $status);
        });
    }
}

