<?php

namespace App\Models\Lending\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Lending\Lending;
use App\Models\User\User;

trait LendingCurrencyRelation
{
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function collateral()
    {
        return $this->belongsTo(Currency::class, 'collateral_id');
    }

    public function lending()
    {
        return $this->belongsTo(Lending::class, 'lending_id');
    }


    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}



