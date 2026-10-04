<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Casts\CryptoCurrencyDecimalShortCast;
use App\Casts\FiatCurrencyDecimalCast;
use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerAdRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerAdScope;
use Database\Factories\P2P\PeerAdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeerAd extends Model
{
    use PeerAdRelation, PeerAdScope;

    public $incrementing = false;

    protected $table = 'peer_ads';

    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return PeerAdFactory::new();
    }

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    public $fillable = [
        'id',
        'user_id',
        'base_currency_id',
        'quote_currency_id',
        'amount',
        'remaining_amount',
        'min_amount',
        'max_amount',
        'type',
        'price',
        'price_type',
        'price_percentage',
        'timeframe',
        'remarks',
        'auto_reply',
        'status',
        'market_id',
        'fee_rate',
        'fee_reserved',
        'fee_remaining',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'amount' => CryptoCurrencyDecimalShortCast::class,
        'min_amount' => CryptoCurrencyDecimalShortCast::class,
        'max_amount' => CryptoCurrencyDecimalShortCast::class,
        'price' => FiatCurrencyDecimalCast::class,
    ];
}
