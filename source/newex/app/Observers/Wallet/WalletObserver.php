<?php

namespace App\Observers\Wallet;

use App\Models\Wallet\Wallet;
use App\Services\Performance\ReadModelCacheService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class WalletObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Wallet $wallet): void
    {
        app(ReadModelCacheService::class)->invalidateWallets((int) $wallet->user_id);
    }

    public function deleted(Wallet $wallet): void
    {
        app(ReadModelCacheService::class)->invalidateWallets((int) $wallet->user_id);
    }
}
