<?php

namespace App\Models\Order;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Order\Traits\Methods\OrderMethods;
use App\Models\Order\Traits\Relations\OrderRelation;
use App\Models\Order\Traits\Scopes\OrderScope;
use Awobaz\Compoships\Compoships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory, OrderScope, OrderRelation, OrderMethods, Compoships;

    const TYPE_MARKET = 'market';
    const TYPE_LIMIT = 'limit';
    const TYPE_STOP_LIMIT = 'stop_limit';

    const STOP_LIMIT_CONDITION_UP = 'up';
    const STOP_LIMIT_CONDITION_DOWN = 'down';

    const SIDE_BUY = 'buy';
    const SIDE_SELL = 'sell';

    const ORDER_DESC = 'desc';
    const ORDER_ASC = 'asc';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    protected $casts = [
        'initial_quantity' => CryptoCurrencyDecimalCast::class,
        'quantity' => CryptoCurrencyDecimalCast::class,
        'initial_quote_quantity' => CryptoCurrencyDecimalCast::class,
        'quote_quantity' => CryptoCurrencyDecimalCast::class,
        'price' => CryptoCurrencyDecimalCast::class,
        'filled_price' => CryptoCurrencyDecimalCast::class,
        'fee' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
        'updated_at' => "datetime:Y-m-d H:i:s",
    ];
}
