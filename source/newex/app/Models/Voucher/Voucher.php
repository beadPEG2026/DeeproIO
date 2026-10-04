<?php

namespace App\Models\Voucher;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Voucher\Traits\Relations\VoucherRelation;
use App\Models\Voucher\Traits\Scopes\VoucherScope;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use VoucherRelation, VoucherScope;

    public $fillable = [
        'user_id',
        'code',
        'is_redeemed',
        'currency_id',
        'code',
        'amount',
    ];

    protected $casts = [
        'is_redeemed' => 'boolean',
        'amount'=> CryptoCurrencyDecimalCast::class,
        'redeemed_at' => 'datetime:Y-m-d H:i:s',
    ];
}
