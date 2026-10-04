<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\Currency\Currency;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait MerchantInvoicePaymentRelation
{
    /**
     * Invoice this payment belongs to
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'invoice_id');
    }

    /**
     * Merchant that owns this payment
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Currency for this payment
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Deposit address this payment was received at
     */
    public function depositAddress(): BelongsTo
    {
        return $this->belongsTo(MerchantDepositAddress::class, 'deposit_address_id');
    }
}
