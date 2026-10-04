<?php

namespace App\Models\Order;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Order\Traits\Relations\OrderHistoryRelation;
use App\Models\Order\Traits\Scopes\OrderHistoryScope;
use Illuminate\Database\Eloquent\Model;

class OrderHistory extends Model
{
    use OrderHistoryScope, OrderHistoryRelation;

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

    protected $fillable = ['id', 'status', 'user_id', 'market_id', 'type', 'quantity', 'price', 'price_trigger', 'trigger_condition', 'base_currency_id', 'quote_currency_id', 'liquidity_id'];

    protected $casts = [
        'initial_quantity' => CryptoCurrencyDecimalCast::class,
        'quantity' => CryptoCurrencyDecimalCast::class,
        'initial_quote_quantity' => CryptoCurrencyDecimalCast::class,
        'quote_quantity' => CryptoCurrencyDecimalCast::class,
        'price' => CryptoCurrencyDecimalCast::class,
        'filled_price' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
