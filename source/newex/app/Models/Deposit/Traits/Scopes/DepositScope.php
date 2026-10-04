<?php

namespace App\Models\Deposit\Traits\Scopes;

trait DepositScope
{
    public function scopeCoin($query)
    {
        return $query->whereType('coin');
    }

    public function scopeFiat($query)
    {
        return $query->whereType('fiat');
    }

    public function scopePending($query)
    {
        return $query->whereStatus('pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->whereStatus('confirmed');
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('deposit_id', 'like', '%'.$search.'%');
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
        })->when($filters['network'] ?? null, function ($query, $network) {
            $query->where('network_id', $network);
        })->when($filters['txn'] ?? null, function ($query, $txn) {
            $query->where('txn', 'like', '%'.$txn.'%');
        })->when($filters['status'] ?? null, function ($query, $status) {

            switch ($status) {
                case 'completed':
                    $state = true; break;
                default:
                    $state = false;
            }

            if($state) {
                $query->where('status', 'confirmed');
            } else {
                $query->where('status', '!=', 'confirmed');
            }

        });
    }

    public function scopeTransferPending($query) {
        return $query->where('wallet_transfer_status', 'pending');
    }

}

