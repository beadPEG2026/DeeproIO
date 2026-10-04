<?php

namespace App\Models\Voucher\Traits\Scopes;

trait VoucherScope
{
    public function scopeRedeemed($query)
    {
        return $query->where('is_redeemed', 1);
    }

    public function scopeActive($query)
    {
        return $query->where('is_redeemed', 0);
    }
}
