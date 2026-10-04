<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\User\User;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantOrphanPayment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait MerchantRefundRelation
{
    /**
     * Merchant that owns this refund
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Invoice this refund is for
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'invoice_id');
    }

    /**
     * Original payment being refunded
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoicePayment::class, 'payment_id');
    }

    /**
     * Orphan payment being refunded
     */
    public function orphanPayment(): BelongsTo
    {
        return $this->belongsTo(MerchantOrphanPayment::class, 'orphan_payment_id');
    }

    /**
     * User who requested the refund
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * User who requested the refund (alias)
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * User who approved the refund
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * User who approved the refund (alias)
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
