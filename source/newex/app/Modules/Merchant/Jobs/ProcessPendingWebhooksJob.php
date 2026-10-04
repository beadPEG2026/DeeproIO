<?php

namespace App\Modules\Merchant\Jobs;

use App\Modules\Merchant\Repositories\WebhookRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPendingWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    /**
     * Execute the job.
     */
    public function handle(WebhookRepository $webhookRepository): void
    {
        // Get pending webhooks
        $pendingWebhooks = $webhookRepository->getPending(100);

        foreach ($pendingWebhooks as $webhook) {
            ProcessWebhookDeliveryJob::dispatch($webhook)
                ->onQueue(config('merchant_acquiring.queues.webhook_delivery', 'merchant-webhooks'));
        }

        // Get webhooks ready for retry
        $retryWebhooks = $webhookRepository->getReadyForRetry(50);

        foreach ($retryWebhooks as $webhook) {
            ProcessWebhookDeliveryJob::dispatch($webhook)
                ->onQueue(config('merchant_acquiring.queues.webhook_delivery', 'merchant-webhooks'));
        }

        $total = $pendingWebhooks->count() + $retryWebhooks->count();

        if ($total > 0) {
            Log::info("Dispatched webhook delivery jobs", [
                'pending' => $pendingWebhooks->count(),
                'retry' => $retryWebhooks->count(),
            ]);
        }
    }
}
