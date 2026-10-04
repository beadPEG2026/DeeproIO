<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;

use App\Models\Country\Country;
use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;

trait PeerAdRelation
{
    public function paymentMethods()
    {
        return $this->belongsToMany(PeerPaymentMethod::class, 'peer_ads_payment_methods', 'ad_id', 'payment_method_id');
    }

    public function regions()
    {
        return $this->belongsToMany(Country::class, 'peer_ads_regions', 'ad_id', 'region_id');
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
}
