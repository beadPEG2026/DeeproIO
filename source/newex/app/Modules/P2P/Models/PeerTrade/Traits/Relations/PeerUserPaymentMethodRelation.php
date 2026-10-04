<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;

trait PeerUserPaymentMethodRelation
{
    public function paymentMethod()
    {
        return $this->belongsTo(PeerPaymentMethod::class, 'payment_method');
    }
}
