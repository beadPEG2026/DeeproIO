<?php

namespace App\Models\CopyTrading;

use Illuminate\Database\Eloquent\Model;

class CopyTradingCopiedOrder extends Model
{
    protected $fillable = [
        'copy_trading_follow_id',
        'source_user_id',
        'follower_user_id',
        'source_contract_id',
        'follower_contract_id',
        'status',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function follow()
    {
        return $this->belongsTo(CopyTradingFollow::class, 'copy_trading_follow_id');
    }
}
