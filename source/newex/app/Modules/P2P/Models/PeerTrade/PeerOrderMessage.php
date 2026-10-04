<?php

namespace App\Modules\P2P\Models\PeerTrade;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeerOrderMessage extends Model
{
    public $incrementing = false;

    protected $table = 'peer_orders_messages';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    use HasFactory;

    public $fillable = [
        'id',
        'message',
        'type',
        'order_id',
        'user_id',
        'is_author',
        'is_system',
        'is_visible_owner',
        'is_visible_counterparty',
        'order_owner_id',
        'order_counterparty_id',
        'seen',
    ];
}
