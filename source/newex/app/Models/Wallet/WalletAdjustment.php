<?php

namespace App\Models\Wallet;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Currency\Currency;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class WalletAdjustment extends Model
{
    protected $table = 'wallet_adjustments';

    protected $fillable = [
        'user_id', 'currency_id', 'wallet_id', 'amount', 'note'
    ];

    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
