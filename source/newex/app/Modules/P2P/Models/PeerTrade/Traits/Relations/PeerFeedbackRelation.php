<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;

trait PeerFeedbackRelation
{
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'post_user_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PeerPaymentMethod::class, 'method_id');
    }
}
