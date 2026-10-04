<?php

namespace App\Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantWebhookDelivery extends Model
{
    protected $table = 'merchant_webhook_deliveries';

    public $timestamps = false;

    protected $fillable = [
        'merchant_id',
        'webhook_id',
        'idempotency_key',
        'status',
        'delivered_at',
        'created_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Merchant relationship
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Webhook relationship
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(MerchantWebhook::class, 'webhook_id');
    }

    /**
     * Check if delivered
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Scope to delivered only
     */
    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }
}
