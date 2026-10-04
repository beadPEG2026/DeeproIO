<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerTradePaymentFieldScope;
use Illuminate\Database\Eloquent\Model;

class PeerPaymentPmCurrency extends Model
{
    protected $table = 'peer_pm_currencies';
}
