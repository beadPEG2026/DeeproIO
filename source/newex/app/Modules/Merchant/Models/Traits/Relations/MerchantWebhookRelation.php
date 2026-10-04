<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantWebhookAttempt;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait MerchantWebhookRelation
{
    /**
     * Merchant that owns this webhook
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Invoice this webhook is for
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'invoice_id');
    }

    /**
     * Delivery attempts for this webhook
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(MerchantWebhookAttempt::class, 'webhook_id')->orderBy('attempt_number', 'asc');
    }

    /**
     * Latest attempt
     */
    public function latestAttempt(): HasMany
    {
        return $this->hasMany(MerchantWebhookAttempt::class, 'webhook_id')->orderBy('attempt_number', 'desc')->limit(1);
    }
}
