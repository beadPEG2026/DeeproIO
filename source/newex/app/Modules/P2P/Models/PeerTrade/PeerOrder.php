<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Casts\CryptoCurrencyDecimalShortCast;
use App\Casts\FiatCurrencyDecimalCast;
use App\Casts\FiatCurrencyDecimalLongCast;
use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerOrderRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerOrderScope;
use Database\Factories\P2P\PeerOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeerOrder extends Model
{
    use PeerOrderRelation, PeerOrderScope;

    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    protected $table = 'peer_orders';

    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return PeerOrderFactory::new();
    }

    public $fillable = [
        'id',
        'user_id',
        'base_currency_id',
        'quote_currency_id',
        'amount',
        'quote_amount',
        'payment_method_id',
        'user_payment_method_id',
        'ad_id',
        'ad_user_id',
        'price',
        'status',
        'type',
        'timeframe',
        'fee_maker',
        'fee_taker',
        'isQuoteAmount',
        'pair'
    ];

    protected $casts = [
        'appealed_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'released_at' => 'datetime',
        'amount' => CryptoCurrencyDecimalShortCast::class,
        'quote_amount' => CryptoCurrencyDecimalShortCast::class,
        'price' => FiatCurrencyDecimalLongCast::class,
    ];
}
