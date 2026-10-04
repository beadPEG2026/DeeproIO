<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Casts\FiatCurrencyDecimalCast;
use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerAdRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerOrderRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerAdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeerUserBlacklist extends Model
{
    protected $table = 'peer_user_blacklist';
}
