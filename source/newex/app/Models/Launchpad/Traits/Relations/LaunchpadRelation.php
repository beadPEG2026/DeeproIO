<?php

namespace App\Models\Launchpad\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Network\Network;

trait LaunchpadRelation
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


