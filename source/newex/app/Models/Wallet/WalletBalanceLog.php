<?php

namespace App\Models\Wallet;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Currency\Currency;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class WalletBalanceLog extends Model
{
    protected $table = 'wallet_balance_logs';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'currency_id',
        'account_field',
        'account_label',
        'change_type',
        'amount',
        'balance_before',
        'balance_after',
        'operation',
        'actor_user_id',
        'actor_email',
        'route_name',
        'request_method',
        'request_path',
        'ip_address',
        'source',
        'source_id',
        'remark',
    ];

    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'balance_before' => CryptoCurrencyDecimalCast::class,
        'balance_after' => CryptoCurrencyDecimalCast::class,
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
