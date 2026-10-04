<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait MerchantDepositAddressRelation
{
    /**
     * Merchant that owns this address
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Invoice this address is assigned to
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'invoice_id');
    }

    /**
     * Currency for this address
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Network for this address
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'network_id');
    }
}
