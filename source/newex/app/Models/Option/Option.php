<?php

namespace App\Models\Option;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Casts\FiatCurrencyDecimalCast;
use App\Models\Option\Traits\Relations\OptionRelation;
use App\Models\Option\Traits\Scopes\OptionScope;
use Database\Factories\Option\OptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    use HasFactory, OptionRelation, OptionScope;

    protected static function newFactory()
    {
        return OptionFactory::new();
    }

    protected $table = 'options';

    protected $casts = [
        'amount' => FiatCurrencyDecimalCast::class,
        'price' => CryptoCurrencyDecimalCast::class,
        'market_price' => CryptoCurrencyDecimalCast::class,
        'pnl' => FiatCurrencyDecimalCast::class,
        'created_at' => 'datetime:Y-m-d H:i:s',
        'start_at' => 'datetime:Y-m-d H:i:s',
        'timeframe_seconds' => 'integer',
    ];
}
