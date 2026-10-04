<?php

namespace App\Models\Transaction;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Transaction\Traits\Relations\TransactionRelation;
use App\Models\Transaction\Traits\Scopes\TransactionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, TransactionScope, TransactionRelation;

    protected $fillable = ['id', 'process_id', 'order_id', 'is_maker', 'is_volume','user_id', 'platform_pool_id', 'market_id', 'order_type', 'order_side', 'price', 'fee', 'referral_fee', 'base_currency', 'quote_currency'];

    protected $casts = [
        'price' => CryptoCurrencyDecimalCast::class,
        'fee' => CryptoCurrencyDecimalCast::class,
        'base_currency' => CryptoCurrencyDecimalCast::class,
        'quote_currency' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
