<?php

namespace App\Modules\Merchant\Models;

use App\Models\Currency\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcquiringAssetRate extends Model
{
    use HasFactory;

    protected $table = 'acquiring_asset_rates';

    protected $fillable = [
        'currency_id',
        'rate_usd',
        'bid_price',
        'ask_price',
        'spread_percent',
        'rate_source',
        'source_pair',
        'fetched_at',
        'valid_until',
        'source_latency_ms',
        'is_stale',
    ];

    protected $casts = [
        'rate_usd' => 'decimal:12',
        'bid_price' => 'decimal:12',
        'ask_price' => 'decimal:12',
        'spread_percent' => 'decimal:4',
        'is_stale' => 'boolean',
        'fetched_at' => 'datetime',
        'valid_until' => 'datetime',
    ];

    /**
     * Currency this rate is for
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Check if rate is still valid
     */
    public function isValid(): bool
    {
        return !$this->is_stale && $this->valid_until->isFuture();
    }

    /**
     * Check if rate is stale
     */
    public function isStale(): bool
    {
        return $this->is_stale || $this->valid_until->isPast();
    }

    /**
     * Get age in seconds
     */
    public function getAgeSeconds(): int
    {
        return $this->fetched_at->diffInSeconds(now());
    }

    /**
     * Get seconds until expiry
     */
    public function getSecondsUntilExpiry(): int
    {
        if ($this->valid_until->isPast()) {
            return 0;
        }
        return now()->diffInSeconds($this->valid_until);
    }

    /**
     * Calculate spread from bid/ask
     */
    public static function calculateSpread(float $bid, float $ask): float
    {
        if ($bid <= 0) {
            return 0;
        }
        return (($ask - $bid) / $bid) * 100;
    }
}
