<?php

namespace App\Models\Wallet;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Wallet\Traits\Relations\TransferCommissionRelation;
use App\Models\Wallet\Traits\Scopes\TransferCommissionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferCommission extends Model
{
    use HasFactory, TransferCommissionScope, TransferCommissionRelation;

    protected $fillable = [
        'user_id', 'wallet_id', 'currency_id', 'direction', 'amount', 'percent', 'commission_amount', 'credited_amount'
    ];

    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'commission_amount' => CryptoCurrencyDecimalCast::class,
        'credited_amount' => CryptoCurrencyDecimalCast::class,
        'percent' => 'decimal:4',
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
