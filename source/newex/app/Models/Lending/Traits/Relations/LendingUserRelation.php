<?php

namespace App\Models\Lending\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Staking\Staking;
use App\Models\User\User;

trait LendingUserRelation
{
    public function staking()
    {
        return $this->belongsTo(Staking::class, 'staking_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
