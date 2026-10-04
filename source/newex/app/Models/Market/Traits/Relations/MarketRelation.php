<?php

namespace App\Models\Market\Traits\Relations;

use App\Models\Currency\Currency;

trait MarketRelation
{
    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function quoteCurrency()
    {
        return $this->belongsTo(Currency::class, 'quote_currency_id');
    }
}


