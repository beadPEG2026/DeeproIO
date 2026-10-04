<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;


use App\Models\Currency\Currency;

trait PeerTradePaymentMethodsRelation
{
    public function currencies()
    {
        return $this->belongsToMany(Currency::class, 'peer_pm_currencies', 'peer_pm_id');
    }
}

