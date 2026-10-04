<?php

namespace App\Models\Order;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Order\Traits\Relations\FuturesContractRelation;
use App\Models\Order\Traits\Scopes\FuturesContractScope;
use Database\Factories\Order\FuturesContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuturesContract extends Model
{
    use HasFactory, FuturesContractRelation, FuturesContractScope;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return FuturesContractFactory::new();
    }

    protected $table = 'futures_contract';

    const TYPE_MARKET = 'market';
    const TYPE_LIMIT = 'limit';

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
        'quantity' => CryptoCurrencyDecimalCast::class,
        'price' => CryptoCurrencyDecimalCast::class,
        'liquidation_price' => CryptoCurrencyDecimalCast::class,
        'take_profit_price' => CryptoCurrencyDecimalCast::class,
        'stop_loss_price' => CryptoCurrencyDecimalCast::class,
        'balance' => CryptoCurrencyDecimalCast::class,
        'released_amount' => CryptoCurrencyDecimalCast::class,
        'total_funding_fee_paid' => CryptoCurrencyDecimalCast::class,
        'entry_fee' => CryptoCurrencyDecimalCast::class,
        'exit_fee' => CryptoCurrencyDecimalCast::class,
        'fee_rate' => 'decimal:4',
        'start_at' => 'datetime:Y-m-d H:i:s',
        'activated_at' => 'datetime:Y-m-d H:i:s',
        'last_funding_fee_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => "datetime:Y-m-d H:i:s",
        'updated_at' => "datetime:Y-m-d H:i:s",
    ];
}
