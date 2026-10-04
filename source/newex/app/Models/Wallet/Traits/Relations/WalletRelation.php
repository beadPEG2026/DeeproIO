<?php

namespace App\Models\Wallet\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\WalletAddress;

trait WalletRelation
{
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function address()
    {
        return $this->hasOne(WalletAddress::class, 'wallet_id');
    }
}



