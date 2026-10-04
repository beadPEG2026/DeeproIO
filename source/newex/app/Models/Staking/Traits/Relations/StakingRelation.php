<?php

namespace App\Models\Staking\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;

trait StakingRelation
{
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
    public function currencyd()
    {
        return $this->belongsTo(Currency::class, 'currency_idd');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}



