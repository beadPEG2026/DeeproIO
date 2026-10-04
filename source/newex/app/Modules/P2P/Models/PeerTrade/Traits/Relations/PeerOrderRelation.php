<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;

trait PeerOrderRelation
{
    public function paymentMethod()
    {
        return $this->belongsTo(PeerPaymentMethod::class, 'payment_method_id');
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function quoteCurrency()
    {
        return $this->belongsTo(Currency::class, 'quote_currency_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'ad_user_id');
    }

    public function ad()
    {
        return $this->belongsTo(PeerAd::class, 'ad_id');
    }
}
