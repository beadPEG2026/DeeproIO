<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerOrderAppealRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerOrderAppealScope;
use Illuminate\Database\Eloquent\Model;

class PeerOrderAppeal extends Model
{
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    use PeerOrderAppealScope, PeerOrderAppealRelation;

    protected $table = 'peer_orders_appeals';
}
