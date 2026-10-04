<?php

namespace App\Models\Transaction\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;

trait ReferralTransactionRelation
{
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}



