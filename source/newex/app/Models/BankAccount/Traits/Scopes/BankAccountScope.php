<?php

namespace App\Models\BankAccount\Traits\Scopes;

trait BankAccountScope
{
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
