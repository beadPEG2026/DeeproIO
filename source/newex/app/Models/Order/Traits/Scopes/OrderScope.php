<?php

namespace App\Models\Order\Traits\Scopes;

use App\Models\Order\Order;

trait OrderScope
{
    public function scopeSellLimit($query)
    {
        return $query->where('type', Order::TYPE_LIMIT)->where('side', Order::SIDE_SELL);
    }

    public function scopeBuyLimit($query)
    {
        return $query->where('type', Order::TYPE_LIMIT)->where('side', Order::SIDE_BUY);
    }

    public function scopeLimitType($query)
    {
        return $query->where('type', Order::TYPE_LIMIT);
    }

    public function scopeStopLimit($query)
    {
        return $query->where('type', Order::TYPE_STOP_LIMIT);
    }

    public function scopeOldest($query)
    {
        return $query->orderBy('created_at', 'ASC');
    }

    public function scopeNewest($query)
    {
        return $query->orderBy('created_at', 'DESC');
    }

    public function scopeNotLocked($query)
    {
        return $query->where('locked', false);
    }

    public function scopeLocked($query)
    {
        return $query->where('locked', false);
    }

    public function scopeGroupPrice($query)
    {
        return $query->selectRaw('price, SUM(quantity) as quantity');
    }

    public function scopeMatchOpposite($query, $type, $side, $price = false) {

        if(order_is_buy($side)) {

            if(order_is_limit($type) && $price) {
                return $query->sellLimit()->where('price', '<=', $price)->lowest();
            }

            return $query->sellLimit()->lowest();
        }

        if(order_is_limit($type) && $price) {
            return $query->buyLimit()->where('price', '>=', $price)->highest();
        }

        return $query->buyLimit()->highest();
    }

    public function scopeMatchByStopLimitCondition($query, $price) {
        return $query->where(function($query) use ($price){

            // Get stop limit orders with price drop condition
            $query->where(function($query) use ($price) {
                $query->where('trigger_price', '<=', $price);
                $query->where('trigger_condition', Order::STOP_LIMIT_CONDITION_UP);
            });

            // Get stop limit orders with price up condition
            $query->orWhere(function($query) use ($price) {
                $query->where('trigger_price', '>=', $price);
                $query->where('trigger_condition', Order::STOP_LIMIT_CONDITION_DOWN);
            });
        });
    }

    public function scopeProcessable($query)
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeHighest($query)
    {
        return $query->orderBy('price', Order::ORDER_DESC);
    }

    public function scopeLowest($query)
    {
        return $query->orderBy('price', Order::ORDER_ASC);
    }
}
