<?php

namespace App\Modules\Merchant\Models\Traits\Relations;

use App\Models\User\User;
use App\Modules\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait MerchantApiKeyRelation
{
    /**
     * Merchant that owns this API key
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * User who created this key
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who revoked this key
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
