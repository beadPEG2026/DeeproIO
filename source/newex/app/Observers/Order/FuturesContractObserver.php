<?php

namespace App\Observers\Order;

use App\Models\Order\FuturesContract;
use App\Services\Performance\ReadModelCacheService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class FuturesContractObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(FuturesContract $contract): void
    {
        app(ReadModelCacheService::class)->invalidateOpenFutures((int) $contract->user_id);
    }

    public function deleted(FuturesContract $contract): void
    {
        app(ReadModelCacheService::class)->invalidateOpenFutures((int) $contract->user_id);
    }
}
