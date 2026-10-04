<?php

namespace App\Models\Deposit;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Casts\FiatCurrencyDecimalCast;
use App\Models\Deposit\Traits\Relations\FiatDepositRelation;
use App\Models\Deposit\Traits\Scopes\FiatDepositScope;
use Illuminate\Database\Eloquent\Model;

class FiatDeposit extends Model
{
    use FiatDepositRelation, FiatDepositScope;

    public $fillable = [
        'deposit_id',
        'amount',
        'fee',
        'user_id',
        'receipt_id',
        'currency_id',
        'status',
        'type',
        'note',
        'approved_at'
    ];

    protected $casts = [
        'amount' => FiatCurrencyDecimalCast::class,
        'fee' => FiatCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
