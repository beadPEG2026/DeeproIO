<?php

namespace App\Models\Order\Traits\Relations;

use App\Models\Market\Market;
use App\Models\User\User;
use App\Models\Wallet\Wallet;

trait OrderRelation
{
    public function market()
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function walletBase()
    {
        return $this->belongsTo(Wallet::class, ['user_id', 'base_currency_id'], ['user_id', 'currency_id']);
    }

    public function walletQuote()
    {
        return $this->belongsTo(Wallet::class, ['user_id', 'quote_currency_id'], ['user_id', 'currency_id']);
    }
}



