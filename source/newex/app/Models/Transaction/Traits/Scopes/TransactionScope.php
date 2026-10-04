<?php

namespace App\Models\Transaction\Traits\Scopes;

use App\Models\Order\Order;

trait TransactionScope
{
    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('process_id', 'like', '%'.$search.'%');
                $query->orWhere('order_id', 'like', '%'.$search.'%');
                $query->orWhere('user_id', 'like', '%'.$search.'%');
                $query->orWhere('order_type', 'like', '%'.$search.'%');
                $query->orWhere('order_side', 'like', '%'.$search.'%');
                $query->orWhere('user_id', 'like', '%'.$search.'%');
                $query->orWhereHas('user', function ($query) use ($search) {
                    return $query->where('email', '=', $search);
                })->get();
            });
        })->when($filters['trashed'] ?? null, function ($query, $trashed) {
            if ($trashed === 'with') {
                $query->withTrashed();
            } elseif ($trashed === 'only') {
                $query->onlyTrashed();
            }
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
        })->when($filters['market'] ?? null, function ($query, $market) {
            $query->where('market_id', $market);
        })->when($filters['side'] ?? null, function ($query, $side) {
            $query->where('order_side', $side);
        });
    }

    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeMaker($query)
    {
        return $query->where('is_maker', true);
    }

    public function scopeTaker($query)
    {
        return $query->where('is_maker', false);
    }

    public function scopeAsks($query) {
        return $query->where('order_side', Order::SIDE_SELL);
    }

    public function scopeBids($query) {
        return $query->where('order_side', Order::SIDE_BUY);
    }
}

