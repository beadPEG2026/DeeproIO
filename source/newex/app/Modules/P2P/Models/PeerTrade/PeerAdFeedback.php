<?php

namespace App\Modules\P2P\Models\PeerTrade;

use Illuminate\Database\Eloquent\Model;

class PeerAdFeedback extends Model
{
    protected $table = 'peer_ads_feedbacks';

    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    protected $casts = [
        'is_negative' => 'boolean',
        'is_anonymous' => 'boolean',
    ];
}
