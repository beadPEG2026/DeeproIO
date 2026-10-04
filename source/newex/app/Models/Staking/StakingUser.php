<?php

namespace App\Models\Staking;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Staking\Traits\Relations\StakingRelation;
use App\Models\Staking\Traits\Scopes\StakingUserScope;
use Database\Factories\Staking\StakingUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Staking\Staking;

class StakingUser extends Model
{
    use HasFactory, StakingUserScope, StakingRelation;

    protected static function newFactory()
    {
        return StakingUserFactory::new();
    }

    protected $table = 'staking_users';

    public $fillable = [
        'user_id',
        'staking_id',
        'amount',
        'days',
        'apy',
        'reward',
        'status',
        'meta',
    ];
    
    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'apy' => CryptoCurrencyDecimalCast::class,
        'reward' => CryptoCurrencyDecimalCast::class,
        'meta' => 'array',
        'value_date' => "datetime:Y-m-d H:i:s",
        'redemption_date' => "datetime:Y-m-d H:i",
        'created_at' => "datetime:Y-m-d H:i",
    ];
    public function staking()
    {
        return $this->belongsTo(Staking::class, 'staking_id');
    }
}
