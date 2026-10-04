<?php

namespace App\Models;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class FundingFeeDistribution extends Model
{
    protected $table = 'funding_fee_distributions';

    protected $casts = [
        'funding_fee_amount' => CryptoCurrencyDecimalCast::class,
        'position_size' => CryptoCurrencyDecimalCast::class,
        'funding_rate' => CryptoCurrencyDecimalCast::class,
        'is_long' => 'boolean',
        'distributed_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected $fillable = [
        'period_key','theoretical_fee','balance_delta','shortfall','price_source',
        'user_id',
        'futures_contract_id',
        'market_id',
        'funding_fee_amount',
        'position_size',
        'funding_rate',
        'is_long',
        'distributed_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function futuresContract()
    {
        return $this->belongsTo(FuturesContract::class, 'futures_contract_id');
    }

    public function market()
    {
        return $this->belongsTo(Market::class);
    }
}
