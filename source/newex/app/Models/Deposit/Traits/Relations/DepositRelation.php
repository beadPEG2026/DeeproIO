<?php

namespace App\Models\Deposit\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Models\User\User;

trait DepositRelation
{
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function network()
    {
        return $this->belongsTo(Network::class, 'network_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}


