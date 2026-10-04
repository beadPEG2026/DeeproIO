<?php

namespace App\Models\Transaction;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Transaction\Traits\Relations\ReferralTransactionRelation;
use App\Models\Transaction\Traits\Scopes\ReferralTransactionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralTransaction extends Model
{
    use HasFactory, ReferralTransactionScope, ReferralTransactionRelation;

    protected $fillable = ['id', 'transaction_id', 'user_id', 'currency_id', 'amount'];

    protected $casts = [
        'amount' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
        'is_credited' => 'boolean',
    ];
}
