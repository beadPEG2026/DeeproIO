<?php

namespace App\Modules\Merchant\Models;

use App\Models\Currency\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantAssetSetting extends Model
{
    use HasFactory;

    protected $table = 'merchant_asset_settings';

    protected $fillable = [
        'merchant_id',
        'currency_id',
        'is_enabled',
        'custom_min_amount_usd',
        'custom_max_amount_usd',
        'custom_fee_percent',
        'custom_confirmations',
        'auto_convert_to_currency_id',
        'auto_convert_enabled',
        'total_invoices',
        'total_volume_usd',
        'last_used_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'auto_convert_enabled' => 'boolean',
        'custom_min_amount_usd' => 'decimal:2',
        'custom_max_amount_usd' => 'decimal:2',
        'custom_fee_percent' => 'decimal:4',
        'total_volume_usd' => 'decimal:2',
        'last_used_at' => 'datetime',
    ];

    /**
     * Merchant that owns this setting
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Currency this setting is for
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Get effective min amount (custom or default)
     */
    public function getEffectiveMinAmount(): float
    {
        return $this->custom_min_amount_usd ?? $this->currency?->merchant_min_amount_usd ?? 10.0;
    }

    /**
     * Get effective max amount (custom or default)
     */
    public function getEffectiveMaxAmount(): float
    {
        return $this->custom_max_amount_usd ?? $this->currency?->merchant_max_amount_usd ?? 100000.0;
    }

    /**
     * Get effective fee percent (custom or default)
     */
    public function getEffectiveFeePercent(): float
    {
        return $this->custom_fee_percent ?? $this->currency?->merchant_fee_percent ?? 1.0;
    }

    /**
     * Get effective confirmations (custom or default)
     */
    public function getEffectiveConfirmations(): int
    {
        return $this->custom_confirmations ?? $this->currency?->merchant_confirmations ?? 3;
    }

    /**
     * Increment usage statistics
     */
    public function incrementUsage(float $volumeUsd): void
    {
        $this->increment('total_invoices');
        $this->increment('total_volume_usd', $volumeUsd);
        $this->update(['last_used_at' => now()]);
    }
}
