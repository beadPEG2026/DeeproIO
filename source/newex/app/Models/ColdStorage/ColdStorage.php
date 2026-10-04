<?php

namespace App\Models\ColdStorage;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\ColdStorage\Traits\Relations\ColdStorageRelation;
use App\Models\ColdStorage\Traits\Scopes\ColdStorageScope;
use Illuminate\Database\Eloquent\Model;

class ColdStorage extends Model
{
    use ColdStorageScope, ColdStorageRelation;

    protected $table = 'cold_storage';

    public $fillable = [
        'currency_id',
        'network_id',
        'address',
        'cold_min_balance_amount',
        'cold_transfer_amount',
        'cold_storage_transaction_id',
        'status'
        ,'hot_reserve','daily_limit','updated_by','approved_by','approved_at'
    ];

    protected $casts = [
        'status' => 'boolean',
        'cold_min_balance_amount' => CryptoCurrencyDecimalCast::class,
        'cold_transfer_amount' => CryptoCurrencyDecimalCast::class,
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
