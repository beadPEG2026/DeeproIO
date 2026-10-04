<?php

namespace App\Models\Lending;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Lending\Traits\Relations\LendingRelation;
use App\Models\Lending\Traits\Scopes\LendingScope;
use Illuminate\Database\Eloquent\Model;

class Lending extends Model
{
    use LendingScope, LendingRelation;

    protected $table = 'lending';

    public $fillable = [
        'currency_id',
        'allowed_days',
        'rewards_percentage',
        'min_amount',
        'max_amount',
        'status',
        'is_flexible',
        'is_weekly',
        'is_monthly',
        'annual_rate_flexible',
        'annual_rate_weekly',
        'annual_rate_monthly'
    ];

    protected $casts = [
        'min_amount' => CryptoCurrencyDecimalCast::class,
        'max_amount' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
        'is_flexible' => 'boolean',
        'is_weekly' => 'boolean',
        'is_monthly' => 'boolean',
    ];
}
