<?php

namespace App\Models\Lending;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Lending\Traits\Relations\LendingRelation;
use App\Models\Lending\Traits\Scopes\LendingUserScope;
use Illuminate\Database\Eloquent\Model;

class LendingUser extends Model
{
    use LendingUserScope, LendingRelation;

    protected $table = 'lending_users';

    public $fillable = [
        'user_id',
        'staking_id',
        'amount',
        'days',
        'apy',
        'reward',
        'status',
    ];

    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'remaining_amount' => CryptoCurrencyDecimalCast::class,
        'collateral_amount'=> CryptoCurrencyDecimalCast::class,
        'apy' => CryptoCurrencyDecimalCast::class,
        'reward' => CryptoCurrencyDecimalCast::class,
        'value_date' => "datetime:Y-m-d H:i:s",
        'redemption_date' => "datetime:Y-m-d H:i",
        'created_at' => "datetime:Y-m-d H:i",
    ];
}
