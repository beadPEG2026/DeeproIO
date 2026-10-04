<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Scopes;


use App\Models\Currency\Currency;
use Illuminate\Support\Facades\DB;

trait PeerUserPaymentMethodScope
{
    public function scopeActive($query)
    {
        return $query->where('is_archived', 'false');
    }
}

