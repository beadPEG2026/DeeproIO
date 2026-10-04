<?php

namespace App\Models\Withdrawal\Traits\Relations;

use App\Models\Country\Country;
use App\Models\Currency\Currency;
use App\Models\User\User;

trait FiatWithdrawalRelation
{
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
}


