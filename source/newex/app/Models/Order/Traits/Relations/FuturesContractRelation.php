<?php

namespace App\Models\Order\Traits\Relations;

use App\Models\Market\Market;
use App\Models\User\User;

trait FuturesContractRelation
{
    public function market()
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}



