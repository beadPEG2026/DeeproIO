<?php

namespace App\Models\Launchpad;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Launchpad\Traits\Relations\LaunchpadTransactionRelation;
use App\Models\Launchpad\Traits\Scopes\LaunchpadTransactionScope;
use Database\Factories\Launchpad\LaunchpadTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaunchpadTransaction extends Model
{
    use HasFactory, LaunchpadTransactionRelation, LaunchpadTransactionScope;

    protected static function newFactory()
    {
        return LaunchpadTransactionFactory::new();
    }

    public $fillable = [
        'id',
        'launchpad_id',
        'user_id',
        'amount',
        'is_credited',
    ];

    protected $casts = [
        'is_credited' => 'boolean',
        'amount' => CryptoCurrencyDecimalCast::class,
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];
}
