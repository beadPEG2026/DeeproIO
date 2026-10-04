<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantInvoiceTimeline;
use App\Modules\Merchant\Models\MerchantRefund;
use App\Modules\Merchant\Models\MerchantWebhook;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait MerchantInvoiceRelation
{
    /**
     * Merchant that owns this invoice
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Currency model selected for this invoice
     * Named currencyModel to avoid conflict with 'currency' column
     */
    public function currencyModel(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Network selected for this invoice
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_id');
    }

    /**
     * Deposit address assigned to this invoice
     */
    public function depositAddressRecord(): BelongsTo
    {
        return $this->belongsTo(MerchantDepositAddress::class, 'deposit_address_id');
    }

    /**
     * Alias for depositAddressRecord
     */
    public function depositAddress(): BelongsTo
    {
        return $this->belongsTo(MerchantDepositAddress::class, 'deposit_address_id');
    }

    /**
     * Payments received for this invoice
     */
    public function payments(): HasMany
    {
        return $this->hasMany(MerchantInvoicePayment::class, 'invoice_id');
    }

    /**
     * Confirmed payments
     */
    public function confirmedPayments(): HasMany
    {
        return $this->payments()->where('status', 'confirmed');
    }

    /**
     * Webhooks sent for this invoice
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(MerchantWebhook::class, 'invoice_id');
    }

    /**
     * Refunds for this invoice
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(MerchantRefund::class, 'invoice_id');
    }

    /**
     * Timeline events for this invoice
     */
    public function timeline(): HasMany
    {
        return $this->hasMany(MerchantInvoiceTimeline::class, 'invoice_id')->orderBy('occurred_at', 'asc');
    }

    /**
     * Latest payment
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(MerchantInvoicePayment::class, 'invoice_id')->orderBy('detected_at', 'desc');
    }
}
