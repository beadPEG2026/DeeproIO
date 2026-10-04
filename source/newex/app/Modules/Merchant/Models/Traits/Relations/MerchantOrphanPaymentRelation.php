<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait MerchantOrphanPaymentRelation
{
    /**
     * Merchant that may own this payment (if identifiable)
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Address where payment was received
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(MerchantDepositAddress::class, 'address_id');
    }

    /**
     * Currency for this payment
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Related expired invoice (if applicable)
     */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'related_invoice_id');
    }

    /**
     * Invoice the payment was credited to (if resolved)
     */
    public function resolutionInvoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'resolution_invoice_id');
    }

    /**
     * User who resolved this orphan payment
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
