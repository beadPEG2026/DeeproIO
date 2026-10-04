<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Scopes;


use App\Models\Currency\Currency;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use Illuminate\Support\Facades\DB;

trait PeerAdScope
{
    public function scopeOrderByLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['type'] ?? 'buy', function ($query, $type) {
            if($type == "sell") {
                $query->whereType('buy');
            } else {
                $query->whereType('sell');
            }
        })->when($filters['basePair'] ?? null, function ($query, $symbol) {
            $query->where('base_currency_id', DB::table('currencies')->where('symbol', $symbol)->value('id'));
        })->when($filters['quotePair'] ?? null, function ($query, $symbol) {
            $query->where('quote_currency_id', DB::table('currencies')->where('symbol', $symbol)->value('id'));
        })->when($filters['amount'] ?? null, function ($query, $amount) use ($filters) {
            $query->where(function($query) use ($amount, $filters){

                if(!isset($filters['basePair']) || !isset($filters['quotePair'])) return;

                $baseCurrency = Currency::where('symbol', $filters['basePair'])->first();
                $usdtCurrency = Currency::where('symbol', 'USDT')->first();
                $quoteCurrency = Currency::where('symbol', $filters['quotePair'])->first();

                if(!$usdtCurrency || !$baseCurrency || !$quoteCurrency) return false;

                $adRepository = new PeerAdRepository();
                $usdRate = $adRepository->getUsdRate($baseCurrency->id, $usdtCurrency->id);
                $rate = $adRepository->getFiatRate($quoteCurrency->id, $usdRate);

                if($rate == 0) return;

                $query->where('remaining_amount', '>=', (float)$amount / $rate);
            });

        })->when($filters['payment_method'] ?? null, function ($query, $ids) {
            $query->whereHas('paymentMethods', function($query) use ($ids) {

                $allowedIds = [];

                foreach ($ids as $key => $val) {
                    $allowedIds[] = (int)$val;
                }

                $query->whereIn('peer_payment_methods.id', $allowedIds);
            });
        })->when($filters['region'] ?? null, function ($query, $region) {
            $query->whereHas('regions', function($query) use ($region) {
                $query->where('peer_ads_regions.id', $region);
            });
        });
    }

    public function scopeUserFilter($query, array $filters)
    {
        return $query->when($filters['type'] ?? null, function ($query, $type) {
            $query->whereType($type);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->whereStatus($status);
        })->when($filters['ad_id'] ?? null, function ($query, $id) {
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

