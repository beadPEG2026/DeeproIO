<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\User\User;
use App\Modules\Merchant\Models\MerchantApiKey;
use App\Modules\Merchant\Models\MerchantAssetSetting;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Models\MerchantRefund;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait MerchantRelation
{
    /**
     * User who owns this merchant account
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * User who verified this merchant
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * API keys for this merchant
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(MerchantApiKey::class, 'merchant_id');
    }

    /**
     * Active API keys
     */
    public function activeApiKeys(): HasMany
    {
        return $this->apiKeys()->where('is_active', true)->whereNull('revoked_at');
    }

    /**
     * Asset settings for this merchant
     */
    public function assetSettings(): HasMany
    {
        return $this->hasMany(MerchantAssetSetting::class, 'merchant_id');
    }

    /**
     * Invoices for this merchant
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantInvoice::class, 'merchant_id');
    }

    /**
     * Recent invoices
     */
    public function recentInvoices(): HasMany
    {
        return $this->invoices()->orderBy('created_at', 'desc')->limit(10);
    }

    /**
     * Webhooks for this merchant
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(MerchantWebhook::class, 'merchant_id');
    }

    /**
     * Pending webhooks
     */
    public function pendingWebhooks(): HasMany
    {
        return $this->webhooks()->whereIn('status', ['pending', 'pending_retry']);
    }

    /**
     * Refunds for this merchant
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(MerchantRefund::class, 'merchant_id');
    }

    /**
     * Deposit addresses for this merchant
     */
    public function depositAddresses(): HasMany
    {
        return $this->hasMany(MerchantDepositAddress::class, 'merchant_id');
    }
}
