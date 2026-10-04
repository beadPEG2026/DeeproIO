<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Modules\Merchant\Jobs\ProcessWebhookDeliveryJob;
use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Models\MerchantWebhookDeadLetter;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Illuminate\Console\Command;

class RetryDeadLetterWebhooksCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'merchant:retry-dead-letters 
                            {--id= : Retry specific dead letter by ID}
                            {--merchant= : Retry all dead letters for a merchant}
                            {--all : Retry all unresolved dead letters}
                            {--limit=50 : Maximum number to process}
                            {--sync : Process synchronously}';

    /**
     * The console command description.
     */
    protected $description = 'Retry webhooks from the dead letter queue';

    /**
     * Execute the console command.
     */
    public function handle(WebhookDispatcherService $webhookService): int
    {
        $limit = (int) $this->option('limit');
        $sync = $this->option('sync');

        // Process specific dead letter
        if ($id = $this->option('id')) {
            return $this->retryDeadLetter((int) $id, $webhookService, $sync);
        }

        // Get dead letters to process
        $query = MerchantWebhookDeadLetter::unresolved()
            ->orderBy('created_at', 'asc');

        if ($merchantId = $this->option('merchant')) {
            $query->where('merchant_id', $merchantId);
        }

        if (!$this->option('all') && !$this->option('merchant')) {
            $this->error("Please specify --all, --merchant=ID, or --id=ID");
            return Command::FAILURE;
        }

        $deadLetters = $query->limit($limit)->get();

        $this->info("Found {$deadLetters->count()} dead letter webhooks to retry");

        $success = 0;
        $failed = 0;

        foreach ($deadLetters as $deadLetter) {
            $this->line("Processing dead letter #{$deadLetter->id}...");
            $this->line("  Webhook: {$deadLetter->webhook_id}");
            $this->line("  Event: {$deadLetter->event_type}");
            $this->line("  Previous attempts: {$deadLetter->total_attempts}");

            // Find the original webhook
            $webhook = MerchantWebhook::find($deadLetter->webhook_id);

            if (!$webhook) {
                $this->warn("  Original webhook not found, skipping");
                $failed++;
                continue;
            }

            // Reset the webhook for retry
            $webhook->update([
                'status' => MerchantWebhook::STATUS_PENDING,
                'attempt_count' => 0,
                'max_attempts' => 3, // Give it 3 more attempts
                'next_retry_at' => null,
                'failed_at' => null,
                'circuit_breaker_active' => false,
            ]);

            if ($sync) {
                $webhook->update(['status' => MerchantWebhook::STATUS_PROCESSING]);
                $result = $webhookService->deliver($webhook);

                if ($result) {
                    $deadLetter->resolve('manual_retry', null, 'Retried via CLI');
                    $this->info("  ✓ Delivered successfully");
                    $success++;
                } else {
                    $this->warn("  ✗ Failed: {$webhook->last_failure_reason}");
                    $failed++;
                }
            } else {
                ProcessWebhookDeliveryJob::dispatch($webhook)
                    ->onQueue(config('merchant_acquiring.queues.webhook_delivery', 'merchant-webhooks'));
                
                $deadLetter->update([
                    'resolution_action' => 'manual_retry',
                    'resolution_notes' => 'Job dispatched via CLI',
                ]);

                $this->info("  Job dispatched");
                $success++;
            }
        }

        $this->newLine();
        $this->info("Results: {$success} succeeded, {$failed} failed");

        return Command::SUCCESS;
    }

    /**
     * Retry a specific dead letter
     */
    protected function retryDeadLetter(int $id, WebhookDispatcherService $webhookService, bool $sync): int
    {
        $deadLetter = MerchantWebhookDeadLetter::find($id);

        if (!$deadLetter) {
            $this->error("Dead letter not found: {$id}");
            return Command::FAILURE;
        }

        if ($deadLetter->isResolved()) {
            $this->warn("Dead letter already resolved");
            return Command::SUCCESS;
        }

        $this->info("Retrying dead letter #{$deadLetter->id}");
        $this->info("  Webhook: {$deadLetter->webhook_id}");
        $this->info("  Event: {$deadLetter->event_type}");
        $this->info("  Merchant: {$deadLetter->merchant_id}");
        $this->info("  Invoice: {$deadLetter->invoice_id}");
        $this->info("  Previous attempts: {$deadLetter->total_attempts}");
        $this->info("  Last error: {$deadLetter->last_error}");

        $webhook = MerchantWebhook::find($deadLetter->webhook_id);

        if (!$webhook) {
            $this->error("Original webhook not found");
            return Command::FAILURE;
        }

        // Reset webhook for retry
        $webhook->update([
            'status' => MerchantWebhook::STATUS_PENDING,
            'attempt_count' => 0,
            'max_attempts' => 3,
            'next_retry_at' => null,
            'failed_at' => null,
            'circuit_breaker_active' => false,
        ]);

        if ($sync) {
            $this->info("Processing synchronously...");
            $webhook->update(['status' => MerchantWebhook::STATUS_PROCESSING]);
            $result = $webhookService->deliver($webhook);

            $webhook->refresh();

            if ($result) {
                $deadLetter->resolve('manual_retry', null, 'Retried via CLI');
                $this->info("✓ Webhook delivered successfully");
                $this->info("  Response code: {$webhook->last_response_code}");
            } else {
                $this->error("✗ Webhook delivery failed");
                $this->error("  Error: {$webhook->last_failure_reason}");
            }
        } else {
            ProcessWebhookDeliveryJob::dispatch($webhook)
                ->onQueue(config('merchant_acquiring.queues.webhook_delivery', 'merchant-webhooks'));
            
            $deadLetter->update([
                'resolution_action' => 'manual_retry',
                'resolution_notes' => 'Job dispatched via CLI',
            ]);

            $this->info("Job dispatched to queue");
        }

        return Command::SUCCESS;
    }
}
