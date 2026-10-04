<?php

namespace App\Models\Staking;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Staking\Traits\Relations\StakingRelation;
use App\Models\Staking\Traits\Scopes\StakingScope;
use Database\Factories\Staking\StakingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staking extends Model
{
    use HasFactory, StakingScope, StakingRelation;

    protected static function newFactory()
    {
        return StakingFactory::new();
    }

    protected $table = 'staking';

    public $fillable = [
        'currency_id',
        'allowed_days',
        'rewards_percentage',
        'min_amount',
        'max_amount',
        'Introduction',
        'rewards_percentage_a',
        'status',
        'staking_type',
        'rewards_percentage_t',
        'currency_idd'
    ];

    protected $casts = [
        'min_amount' => CryptoCurrencyDecimalCast::class,
        'max_amount' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
