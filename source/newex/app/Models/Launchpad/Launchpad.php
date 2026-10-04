<?php

namespace App\Models\Launchpad;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Launchpad\Traits\Relations\LaunchpadRelation;
use App\Models\Launchpad\Traits\Scopes\LaunchpadScope;
use Database\Factories\Launchpad\LaunchpadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Launchpad extends Model
{
    use HasFactory, LaunchpadRelation, LaunchpadScope;

    protected static function newFactory()
    {
        return LaunchpadFactory::new();
    }

    public $fillable = [
        'publication_status', 'publication_reference', 'publication_reviewed_by', 'publication_reviewed_at',
        'id',
        'name',
        'description',
        'currency_id',
        'network_id',
        'rate',
        'min_buy',
        'max_buy',
        'soft_cap',
        'hard_cap',
        'start_time',
        'end_time',
        'status',
        'dy_am',
        'kt_sl',
        'purchasable',
        'raised_amount',
        'progress'
    ];

    public function scopePublished($query)
    {
        return $query->where('publication_status', 'published')
            ->whereNotNull('publication_reviewed_by')->whereNotNull('publication_reviewed_at');
    }

    public function isPublished(): bool
    {
        return $this->publication_status === 'published' && $this->publication_reviewed_by !== null && $this->publication_reviewed_at !== null;
    }

    protected $casts = [
        'status' => 'boolean',
        'purchasable' => 'boolean',
        'rate' => CryptoCurrencyDecimalCast::class,
        'min_buy' => CryptoCurrencyDecimalCast::class,
        'max_buy' => CryptoCurrencyDecimalCast::class,
        'soft_cap' => CryptoCurrencyDecimalCast::class,
        'hard_cap' => CryptoCurrencyDecimalCast::class,
        'raised_amount' => CryptoCurrencyDecimalCast::class,
        'start_time' => 'datetime:Y-m-d H:i:s',
        'end_time' => 'datetime:Y-m-d H:i:s'
    ];
}
