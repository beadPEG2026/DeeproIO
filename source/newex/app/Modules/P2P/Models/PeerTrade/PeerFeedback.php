<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerFeedbackRelation;
use Illuminate\Database\Eloquent\Model;

class PeerFeedback extends Model
{
    use PeerFeedbackRelation;

    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    protected $table = 'peer_ads_feedbacks';

    protected $casts = [
        'created_at' => "datetime:Y-m-d",
    ];
}
