<?php

namespace App\Models\CopyTrading;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class CopyTradingFollow extends Model
{
    protected $fillable = [
        'copy_trading_trader_id',
        'trader_user_id',
        'follower_user_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function trader()
    {
        return $this->belongsTo(CopyTradingTrader::class, 'copy_trading_trader_id');
    }

    public function traderUser()
    {
        return $this->belongsTo(User::class, 'trader_user_id');
    }

    public function follower()
    {
        return $this->belongsTo(User::class, 'follower_user_id');
    }

    public function copiedOrders()
    {
        return $this->hasMany(CopyTradingCopiedOrder::class, 'copy_trading_follow_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
