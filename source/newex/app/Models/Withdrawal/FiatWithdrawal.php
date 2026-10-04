<?php

namespace App\Models\Withdrawal;

use App\Casts\FiatCurrencyDecimalCast;
use App\Models\Withdrawal\Traits\Relations\FiatWithdrawalRelation;
use App\Models\Withdrawal\Traits\Scopes\FiatWithdrawalScope;
use Illuminate\Database\Eloquent\Model;

class FiatWithdrawal extends Model
{
    use FiatWithdrawalScope, FiatWithdrawalRelation;

    public $fillable = [
        'withdrawal_id',
        'name',
        'iban',
        'swift',
        'ifsc',
        'address',
        'account_holder_name',
        'account_holder_address',
        'country_id',
        'amount',
        'fee',
        'currency_id',
        'user_id',
        'status',
        'note',
        'inusd',
        'type'
    ];

    protected $casts = [
        'amount' => FiatCurrencyDecimalCast::class,
        'fee' => FiatCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
