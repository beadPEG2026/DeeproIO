<?php

namespace App\Models\Wallet\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;

trait TransferCommissionRelation
{
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
