<?php

namespace App\Models\Option\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\User\User;

trait OptionRelation
{
    public function market()
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}



