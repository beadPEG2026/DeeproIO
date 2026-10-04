<?php

namespace App\Models\Withdrawal\Traits\Scopes;

trait WithdrawalScope
{
    public function scopeCoin($query)
    {
        return $query->whereType('coin');
    }

    public function scopeFiat($query)
    {
        return $query->whereType('fiat');
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('withdrawal_id', 'like', '%'.$search.'%');
                $query->orWhere('txn', 'like', '%'.$search.'%');
                $query->orWhere('amount', 'like', '%'.$search.'%');
                $query->orWhere('address', 'like', '%'.$search.'%');
                $query->orWhere('payment_id', 'like', '%'.$search.'%');
                $query->orWhere('user_id', 'like', '%'.$search.'%');
                $query->orWhereHas('user', function ($query) use ($search) {
                    return $query->where('email', '=', $search);
                })->get();
            });
        })->when($filters['type'] ?? null, function ($query, $type) {
            $query->whereType($type);
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
        })->when($filters['txn'] ?? null, function ($query, $txn) {
            $query->where('txn', 'like', '%'.$txn.'%');
        })->when($filters['status'] ?? null, function ($query, $status) {

            switch ($status) {
                case 'completed':
                    $state = 'confirmed_provider'; break;
                case 'rejected':
                    $state = 'rejected'; break;
                case 'failed':
                    $state = 'failed'; break;
                default:
                    $state = 'pending';
            }

            if($state == "pending") {
                $query->where('status', '!=', 'confirmed_provider');
                $query->where('status', '!=', 'rejected');
                $query->where('status', '!=', 'failed');
            } else {
                $query->where('status', $state);
            }


        });
    }
}

