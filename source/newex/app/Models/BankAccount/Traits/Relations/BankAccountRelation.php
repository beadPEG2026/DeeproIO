<?php

namespace App\Models\BankAccount\Traits\Relations;

use App\Models\Country\Country;

trait BankAccountRelation
{
    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
}


