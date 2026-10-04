<?php

namespace App\Modules\Merchant\Jobs;

use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Single attempt per job, retries managed by service

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public MerchantWebhook $webhook
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WebhookDispatcherService $webhookService): void
    {
        // Refresh webhook state
        $this->webhook->refresh();

        // Skip if already delivered or in non-deliverable state
        if (in_array($this->webhook->status, [
            MerchantWebhook::STATUS_DELIVERED,
            MerchantWebhook::STATUS_SKIPPED_DUPLICATE,
            MerchantWebhook::STATUS_FAILED,
        ])) {
            Log::debug("Webhook already processed", ['webhook_id' => $this->webhook->id]);
            return;
        }

        // Skip if max attempts already reached
        if ($this->webhook->attempt_count >= $this->webhook->max_attempts) {
            Log::debug("Webhook max attempts reached", [
                'webhook_id' => $this->webhook->id,
                'attempts' => $this->webhook->attempt_count,
                'max' => $this->webhook->max_attempts,
            ]);
            return;
        }

        // Mark as processing
        $this->webhook->update(['status' => MerchantWebhook::STATUS_PROCESSING]);

        // Attempt delivery
        $webhookService->deliver($this->webhook);

        // Note: Retry scheduling is handled by WebhookDispatcherService::handleFailure()
        // which sets status to 'pending_retry' and next_retry_at.
        // The ProcessMerchantWebhooksCommand picks up these retries based on next_retry_at.
        // We do NOT dispatch a new job here to avoid duplicate retry mechanisms.
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Webhook delivery job failed", [
            'webhook_id' => $this->webhook->id,
            'error' => $exception->getMessage(),
        ]);

        $this->webhook->update([
            'status' => MerchantWebhook::STATUS_PENDING_RETRY,
            'last_failure_reason' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'webhook',
            'merchant:' . $this->webhook->merchant_id,
            'invoice:' . $this->webhook->invoice_id,
        ];
    }
}
