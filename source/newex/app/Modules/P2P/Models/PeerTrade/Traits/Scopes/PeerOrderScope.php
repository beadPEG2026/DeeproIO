<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Scopes;


use App\Models\Currency\Currency;
use Illuminate\Support\Facades\DB;

trait PeerOrderScope
{
    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUserFilter($query, array $filters)
    {
        return $query->when($filters['type'] ?? null, function ($query, $type) {
            $query->whereType($type);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->whereStatus($status);
        })->when($filters['order_id'] ?? null, function ($query, $id) {
            $query->where('id', 'like', '%' . $id . '%');
        })->when($filters['coin'] ?? null, function ($query, $coin) {
            $query->where('base_currency_id', $coin);
        })->when($filters['fiat'] ?? null, function ($query, $fiat) {
            $query->where('quote_currency_id', $fiat);
        })->when($filters['date'] ?? null, function ($query, $date) {
            if(isset($date[0]) && isset($date[1]) && is_date_valid($date[0]) && is_date_valid($date[1])) {
                $query->whereBetween('created_at', [$date[0] . ' 00:00:01', $date[1] . ' 23:59:59']);
            } elseif(isset($date[0]) && is_date_valid($date[0])) {
                $query->whereBetween('created_at', [$date[0] . ' 00:00:01', $date[0] . ' 23:59:59']);
            }
        });
    }
}

