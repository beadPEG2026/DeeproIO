<?php

namespace App\Models\Order\Traits\Relations;

use App\Models\Market\Market;
use App\Models\Transaction\Transaction;

trait OrderHistoryRelation
{
    public function market()
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'order_id');
    }
}



