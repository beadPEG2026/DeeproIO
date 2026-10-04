<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerUserPaymentMethodRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerUserPaymentMethodScope;
use Illuminate\Database\Eloquent\Model;

class PeerUserPaymentMethod extends Model
{
    use PeerUserPaymentMethodRelation, PeerUserPaymentMethodScope;
}
