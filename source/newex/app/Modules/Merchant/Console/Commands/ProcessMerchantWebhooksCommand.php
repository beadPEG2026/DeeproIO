<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Modules\Merchant\Jobs\ProcessWebhookDeliveryJob;
use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Repositories\WebhookRepository;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Illuminate\Console\Command;

class ProcessMerchantWebhooksCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'merchant:process-webhooks
                            {--pending : Process pending webhooks}
                            {--retry : Process webhooks ready for retry}
                            {--failed : Retry all failed webhooks}
                            {--id= : Process specific webhook by ID}
                            {--limit=100 : Maximum number of webhooks to process}
                            {--sync : Process synchronously instead of dispatching jobs}';

    /**
     * The console command description.
     */
    protected $description = 'Process pending merchant webhooks and retry failed ones';

    /**
     * Execute the console command.
     */
    public function handle(WebhookRepository $webhookRepository, WebhookDispatcherService $webhookService): int
    {
        $limit = (int) $this->option('limit');
        $sync = $this->option('sync');

        // Process specific webhook by ID
        if ($id = $this->option('id')) {
            return $this->processWebhook($id, $webhookService, $sync);
        }

        $totalProcessed = 0;

        // Process pending webhooks
        if ($this->option('pending') || (!$this->option('retry') && !$this->option('failed'))) {
            $pending = $webhookRepository->getPending($limit);
            $this->info("Found {$pending->count()} pending webhooks");

            foreach ($pending as $webhook) {
                $this->processWebhookRecord($webhook, $webhookService, $sync);
                $totalProcessed++;
            }
        }

        // Process webhooks ready for retry
        if ($this->option('retry') || (!$this->option('pending') && !$this->option('failed'))) {
            $retry = $webhookRepository->getReadyForRetry($limit);
            $this->info("Found {$retry->count()} webhooks ready for retry");

            foreach ($retry as $webhook) {
                $this->processWebhookRecord($webhook, $webhookService, $sync);
                $totalProcessed++;
            }
        }

        // Retry all failed webhooks (manual intervention)
        if ($this->option('failed')) {
            $failed = MerchantWebhook::failed()
                ->orderByPriority()
                ->limit($limit)
                ->get();

            $this->info("Found {$failed->count()} failed webhooks to retry");

            foreach ($failed as $webhook) {
                // Reset the webhook for retry
                $webhook->update([
                    'status' => MerchantWebhook::STATUS_PENDING,
                    'attempt_count' => 0,
                    'next_retry_at' => null,
                    'failed_at' => null,
                    'circuit_breaker_active' => false,
                ]);

                $this->processWebhookRecord($webhook, $webhookService, $sync);
                $totalProcessed++;
            }
        }

        $this->info("Processed {$totalProcessed} webhooks");

        return Command::SUCCESS;
    }

    /**
     * Process a single webhook by ID
     */
    protected function processWebhook(string $id, WebhookDispatcherService $webhookService, bool $sync): int
    {
        $webhook = MerchantWebhook::find($id);

        if (!$webhook) {
            $this->error("Webhook not found: {$id}");
            return Command::FAILURE;
        }

        $this->info("Processing webhook: {$webhook->id}");
        $this->info("  Event: {$webhook->event_type}");
        $this->info("  Status: {$webhook->status}");
        $this->info("  Attempts: {$webhook->attempt_count}/{$webhook->max_attempts}");

        if ($webhook->status === MerchantWebhook::STATUS_DELIVERED) {
            $this->warn("Webhook already delivered");
            return Command::SUCCESS;
        }

        // Reset if failed
        if ($webhook->status === MerchantWebhook::STATUS_FAILED) {
            $this->info("Resetting failed webhook for retry...");
            $webhook->update([
                'status' => MerchantWebhook::STATUS_PENDING,
                'failed_at' => null,
                'circuit_breaker_active' => false,
            ]);
        }

        $this->processWebhookRecord($webhook->fresh(), $webhookService, $sync);

        $webhook->refresh();
        $this->info("Result: {$webhook->status}");

        if ($webhook->status === MerchantWebhook::STATUS_DELIVERED) {
            $this->info("✓ Webhook delivered successfully");
        } else {
            $this->warn("✗ Webhook delivery failed: {$webhook->last_failure_reason}");
        }

        return Command::SUCCESS;
    }

    /**
     * Process a webhook record
     */
    protected function processWebhookRecord(MerchantWebhook $webhook, WebhookDispatcherService $webhookService, bool $sync): void
    {
        if ($sync) {

            $this->line("  Processing: {$webhook->id} ({$webhook->event_type})...");

            $webhook->update(['status' => MerchantWebhook::STATUS_PROCESSING]);
            $success = $webhookService->deliver($webhook);

            if ($success) {
                $this->line("    ✓ Delivered");
            } else {
                $this->line("    ✗ Failed: {$webhook->last_failure_reason}");
            }
        } else {
            ProcessWebhookDeliveryJob::dispatch($webhook)
                ->onQueue(config('merchant_acquiring.queues.webhook_delivery', 'merchant-webhooks'));

            $this->line("  Dispatched job for: {$webhook->id}");
        }
    }
}
