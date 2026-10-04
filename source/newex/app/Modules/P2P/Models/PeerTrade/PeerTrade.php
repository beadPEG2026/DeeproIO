<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Casts\CryptoCurrencyDecimalCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeerTrade extends Model
{
    use HasFactory, SoftDeletes;

    public $fillable = [
        'name',
        'currency_id',
        'price',
        'min_amount',
        'max_amount',
        'available_amount',
        'status'
    ];

    protected $casts = [
        'price' => CryptoCurrencyDecimalCast::class,
        'min_amount' => CryptoCurrencyDecimalCast::class,
        'max_amount' => CryptoCurrencyDecimalCast::class,
    ];
}
