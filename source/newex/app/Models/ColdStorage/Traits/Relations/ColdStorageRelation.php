<?php

namespace App\Models\ColdStorage\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Network\Network;

trait ColdStorageRelation
{
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function network()
    {
        return $this->belongsTo(Network::class, 'network_id');
    }
}


