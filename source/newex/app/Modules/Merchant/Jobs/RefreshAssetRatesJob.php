<?php

namespace App\Modules\Merchant\Jobs;

use App\Modules\Merchant\Services\PricingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshAssetRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /**
     * Execute the job.
     */
    public function handle(PricingService $pricingService): void
    {
        $refreshed = $pricingService->refreshStaleRates();

        if ($refreshed > 0) {
            Log::info("Asset rates refreshed", ['count' => $refreshed]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Rate refresh job failed", [
            'error' => $exception->getMessage(),
        ]);
    }
}
