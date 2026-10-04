<?php

namespace App\Models\Bonus;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Currency\Currency;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class DepositBonus extends Model
{
    protected $table = 'deposit_bonuses';

    protected $fillable = [
        'user_id', 'source_user_id', 'deposit_id', 'currency_id',
        'amount_deposited', 'percent', 'bonus_amount', 'type'
    ];

    protected $casts = [
        'amount_deposited' => CryptoCurrencyDecimalCast::class,
        'bonus_amount' => CryptoCurrencyDecimalCast::class,
        'percent' => 'decimal:4',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sourceUser()
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
