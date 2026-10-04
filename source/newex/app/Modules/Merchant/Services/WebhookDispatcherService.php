<?php

namespace App\Modules\Merchant\Services;

use App\Modules\Merchant\Enums\WebhookEventType;
use App\Modules\Merchant\Enums\WebhookPriority;
use App\Modules\Merchant\Jobs\ProcessWebhookDeliveryJob;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Models\MerchantWebhookAttempt;
use App\Modules\Merchant\Models\MerchantWebhookDeadLetter;
use App\Modules\Merchant\Models\MerchantWebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookDispatcherService
{
    /**
     * Dispatch a webhook for an invoice event
     */
    public function dispatch(
        MerchantInvoice $invoice,
        WebhookEventType $eventType,
        array $additionalData = []
    ): ?MerchantWebhook {

        $merchant = $invoice->merchant;
        $webhookUrl = $invoice->webhook_url ?? $merchant->default_webhook_url;

        // No webhook URL configured
        if (empty($webhookUrl)) {
            Log::debug("No webhook URL for invoice {$invoice->id}");
            return null;
        }

        // Check if this event type is enabled for the merchant
        if (!$this->isEventEnabled($merchant, $eventType)) {
            return null;
        }

        // Generate idempotency key
        $idempotencyKey = $this->generateIdempotencyKey($invoice, $eventType);

        // Check for duplicate
        if ($this->isDuplicate($merchant->id, $idempotencyKey)) {
            Log::debug("Duplicate webhook skipped", [
                'invoice_id' => $invoice->id,
                'event' => $eventType->value,
                'idempotency_key' => $idempotencyKey,
            ]);
            return null;
        }

        // Build payload
        $payload = $this->buildPayload($invoice, $eventType, $additionalData);

        // Determine priority
        $priority = $eventType->priority();

        // Create webhook record
        $webhook = MerchantWebhook::create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $merchant->id,
            'invoice_id' => $invoice->id,
            'event_type' => $eventType->value,
            'priority' => $priority->value,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'webhook_url' => $webhookUrl,
            'status' => MerchantWebhook::STATUS_PENDING,
            'max_attempts' => $priority->maxAttempts(),
        ]);

        // Dispatch job for async delivery
        $queue = config('merchant_acquiring.queues.webhook_delivery', 'merchant-webhooks');
        ProcessWebhookDeliveryJob::dispatch($webhook)->onQueue($queue);

        return $webhook;
    }

    /**
     * Deliver a webhook (called from job)
     */
    public function deliver(MerchantWebhook $webhook): bool
    {
        $merchant = $webhook->merchant;

        // Check circuit breaker
        if ($this->isCircuitBreakerOpen($merchant)) {
            $webhook->update(['circuit_breaker_active' => true]);
            return false;
        }

        // Prepare request
        $timestamp = now()->timestamp;
        $signature = $this->generateSignature(
            $webhook->payload,
            $timestamp,
            $merchant->webhook_secret
        );

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'CryptoAcquiring-Webhook/1.0',
            'X-Webhook-Id' => $webhook->id,
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => $signature,
            'X-Idempotency-Key' => $webhook->idempotency_key,
            'X-Event-Type' => $webhook->event_type,
        ];

        $startTime = microtime(true);

        try {
            $response = Http::timeout(config('merchant_acquiring.webhook.timeout', 10))
                ->connectTimeout(config('merchant_acquiring.webhook.connect_timeout', 5))
                ->withHeaders($headers)
                ->post($webhook->webhook_url, $webhook->payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $responseCode = $response->status();
            $responseBody = $response->body();

            // Record attempt
            MerchantWebhookAttempt::recordSuccess(
                $webhook,
                $responseCode,
                substr($responseBody, 0, 10000),
                $response->headers(),
                $responseTimeMs
            );

            // Check if successful (2xx)
            if ($response->successful()) {
                $this->markDelivered($webhook, $responseCode, $responseBody, $responseTimeMs);
                $this->recordSuccessfulDelivery($webhook);
                $this->resetCircuitBreaker($merchant);
                return true;
            }

            // Non-2xx response - schedule retry
            $this->handleFailure($webhook, "HTTP {$responseCode}", $responseCode, $responseBody);
            return false;

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            MerchantWebhookAttempt::recordFailure(
                $webhook,
                MerchantWebhookAttempt::STATUS_CONNECTION_ERROR,
                null,
                null,
                $e->getMessage(),
                MerchantWebhookAttempt::ERROR_CONNECTION_REFUSED,
                $responseTimeMs
            );

            $this->handleFailure($webhook, 'Connection error: ' . $e->getMessage(), null, null);
            $this->incrementCircuitBreaker($merchant);
            return false;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            MerchantWebhookAttempt::recordFailure(
                $webhook,
                MerchantWebhookAttempt::STATUS_TIMEOUT,
                null,
                null,
                $e->getMessage(),
                MerchantWebhookAttempt::ERROR_TIMEOUT,
                $responseTimeMs
            );

            $this->handleFailure($webhook, 'Request timeout', null, null);
            return false;

        } catch (\Exception $e) {
            Log::error("Webhook delivery failed", [
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage(),
            ]);

            MerchantWebhookAttempt::recordFailure(
                $webhook,
                MerchantWebhookAttempt::STATUS_FAILED,
                null,
                null,
                $e->getMessage(),
                null
            );

            $this->handleFailure($webhook, $e->getMessage(), null, null);
            return false;
        }
    }

    /**
     * Build webhook payload
     */
    protected function buildPayload(
        MerchantInvoice $invoice,
        WebhookEventType $eventType,
        array $additionalData = []
    ): array {
        $timestamp = now()->toIso8601String();

        return [
            'id' => 'whk_' . Str::random(16),
            'idempotency_key' => $this->generateIdempotencyKey($invoice, $eventType),
            'timestamp' => $timestamp,
            'api_version' => config('merchant_acquiring.api_version', '2024-01-01'),
            'event' => [
                'type' => $eventType->value,
                'created_at' => $timestamp,
            ],
            'data' => [
                'object' => 'invoice',
                'invoice' => $this->serializeInvoice($invoice),
                ...$additionalData,
            ],
            'previous_state' => $invoice->previous_status ? [
                'status' => $invoice->previous_status,
            ] : null,
        ];
    }

    /**
     * Serialize invoice for webhook payload
     */
    protected function serializeInvoice(MerchantInvoice $invoice): array
    {
        $data = [
            'id' => $invoice->id,
            'external_id' => $invoice->external_id,
            'status' => $invoice->status,
            'amount_usd' => $invoice->amount_usd,
        ];

        // Add crypto details if currency selected
        if ($invoice->currency_id) {
            $currency = $invoice->currencyModel;
            $network = $invoice->network;
            $data = array_merge($data, [
                'amount_crypto' => $invoice->amount_crypto,
                'amount_received_crypto' => $invoice->amount_received_crypto,
                'amount_received_usd' => $invoice->amount_received_usd,
                'currency' => $currency?->symbol,
                'network' => $network?->slug,
                'asset_code' => $currency ? ($currency->symbol . '_' . ($network?->slug ?? 'unknown')) : null,
                'rate_usd' => $invoice->rate_usd,
                'deposit_address' => $invoice->deposit_address,
                'deposit_memo' => $invoice->deposit_memo,
            ]);
        }

        // Add timestamps
        $data['created_at'] = $invoice->created_at?->toIso8601String();
        $data['paid_at'] = $invoice->paid_at?->toIso8601String();
        $data['settled_at'] = $invoice->settled_at?->toIso8601String();
        $data['expired_at'] = $invoice->expired_at?->toIso8601String();
        $data['cancelled_at'] = $invoice->cancelled_at?->toIso8601String();

        // Add metadata
        $data['metadata'] = $invoice->metadata;
        $data['customer_email'] = $invoice->customer_email;
        $data['description'] = $invoice->description;

        return array_filter($data, fn($v) => $v !== null);
    }

    /**
     * Generate idempotency key
     */
    protected function generateIdempotencyKey(MerchantInvoice $invoice, WebhookEventType $eventType): string
    {
        // Include payment hash for payment-related events to differentiate multiple payments
        $suffix = '';
        if (in_array($eventType, [
            WebhookEventType::INVOICE_PAID,
            WebhookEventType::INVOICE_OVERPAID,
            WebhookEventType::INVOICE_UNDERPAID,
        ])) {
            $latestPayment = $invoice->latestPayment;
            if ($latestPayment) {
                $suffix = '_' . substr($latestPayment->txn_hash ?? '', 0, 8);
            }
        }

        return "{$invoice->id}_{$eventType->value}{$suffix}_" . now()->timestamp;
    }

    /**
     * Generate HMAC signature
     */
    public function generateSignature(array $payload, int $timestamp, ?string $secret): string
    {
        if (empty($secret)) {
            return '';
        }

        $signedPayload = $timestamp . '.' . json_encode($payload);
        return 'v1=' . hash_hmac('sha256', $signedPayload, $secret);
    }

    /**
     * Verify webhook signature (for merchant verification endpoint)
     */
    public function verifySignature(string $payload, string $signature, int $timestamp, string $secret): bool
    {
        // Check timestamp is recent
        $tolerance = config('merchant_acquiring.webhook.timestamp_tolerance', 300);
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expectedSignature = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check if event is enabled for merchant
     */
    protected function isEventEnabled(Merchant $merchant, WebhookEventType $eventType): bool
    {
        $enabledEvents = $merchant->webhook_events;

        // If no specific events configured, send all
        if (empty($enabledEvents)) {
            return true;
        }

        return in_array($eventType->value, $enabledEvents);
    }

    /**
     * Check for duplicate webhook
     */
    protected function isDuplicate(string $merchantId, string $idempotencyKey): bool
    {
        return MerchantWebhookDelivery::where('merchant_id', $merchantId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'delivered')
            ->exists();
    }

    /**
     * Mark webhook as delivered
     */
    protected function markDelivered(
        MerchantWebhook $webhook,
        int $responseCode,
        ?string $responseBody,
        int $responseTimeMs
    ): void {
        $webhook->markDelivered($responseCode, substr($responseBody ?? '', 0, 1000), $responseTimeMs);

        Log::info("Webhook delivered", [
            'webhook_id' => $webhook->id,
            'invoice_id' => $webhook->invoice_id,
            'event' => $webhook->event_type,
            'response_code' => $responseCode,
            'response_time_ms' => $responseTimeMs,
        ]);
    }

    /**
     * Record successful delivery for idempotency
     */
    protected function recordSuccessfulDelivery(MerchantWebhook $webhook): void
    {
        MerchantWebhookDelivery::create([
            'merchant_id' => $webhook->merchant_id,
            'webhook_id' => $webhook->id,
            'idempotency_key' => $webhook->idempotency_key,
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    /**
     * Handle delivery failure
     */
    protected function handleFailure(
        MerchantWebhook $webhook,
        string $reason,
        ?int $responseCode,
        ?string $responseBody
    ): void {
        // Record attempt details (does not increment counter)
        $webhook->recordAttempt(
            $responseCode ?? 0,
            $responseBody,
            null,
            false
        );

        $webhook->update([
            'last_failure_reason' => $reason,
            'last_response_code' => $responseCode,
            'last_response_body' => substr($responseBody ?? '', 0, 1000),
        ]);

        // Check if should retry BEFORE incrementing attempt_count
        // canRetry() checks attempt_count < max_attempts
        if ($webhook->canRetry()) {
            // scheduleRetry() increments attempt_count and sets next_retry_at
            $webhook->scheduleRetry();

            Log::warning("Webhook delivery failed, scheduled retry", [
                'webhook_id' => $webhook->id,
                'attempt' => $webhook->attempt_count,
                'max_attempts' => $webhook->max_attempts,
                'next_retry' => $webhook->next_retry_at,
                'reason' => $reason,
            ]);
        } else {
            // Max attempts reached or non-retryable status - move to dead letter queue
            Log::warning("Webhook max attempts reached, moving to dead letter", [
                'webhook_id' => $webhook->id,
                'attempt' => $webhook->attempt_count,
                'max_attempts' => $webhook->max_attempts,
                'reason' => $reason,
            ]);
            $this->moveToDeadLetter($webhook);
        }
    }

    /**
     * Move webhook to dead letter queue
     */
    protected function moveToDeadLetter(MerchantWebhook $webhook): void
    {
        $webhook->markFailed('Maximum retry attempts reached');

        MerchantWebhookDeadLetter::create([
            'webhook_id' => $webhook->id,
            'merchant_id' => $webhook->merchant_id,
            'invoice_id' => $webhook->invoice_id,
            'event_type' => $webhook->event_type,
            'priority' => $webhook->priority,
            'payload' => $webhook->payload,
            'total_attempts' => $webhook->attempt_count,
            'first_attempt_at' => $webhook->created_at,
            'last_attempt_at' => $webhook->last_attempt_at,
            'last_response_code' => $webhook->last_response_code,
            'last_error' => $webhook->last_failure_reason,
        ]);

        Log::error("Webhook moved to dead letter queue", [
            'webhook_id' => $webhook->id,
            'merchant_id' => $webhook->merchant_id,
            'invoice_id' => $webhook->invoice_id,
            'event' => $webhook->event_type,
            'total_attempts' => $webhook->attempt_count,
        ]);
    }

    /**
     * Check if circuit breaker is open for merchant
     */
    protected function isCircuitBreakerOpen(Merchant $merchant): bool
    {
        $cacheKey = "webhook_circuit_breaker:{$merchant->id}";
        $failures = cache()->get($cacheKey, 0);

        $threshold = config('merchant_acquiring.webhook.circuit_breaker_threshold', 5);

        return $failures >= $threshold;
    }

    /**
     * Increment circuit breaker counter
     */
    protected function incrementCircuitBreaker(Merchant $merchant): void
    {
        $cacheKey = "webhook_circuit_breaker:{$merchant->id}";
        $resetTime = config('merchant_acquiring.webhook.circuit_breaker_reset_time', 3600);

        $failures = cache()->get($cacheKey, 0) + 1;
        cache()->put($cacheKey, $failures, $resetTime);
    }

    /**
     * Reset circuit breaker on success
     */
    protected function resetCircuitBreaker(Merchant $merchant): void
    {
        $cacheKey = "webhook_circuit_breaker:{$merchant->id}";
        cache()->forget($cacheKey);
    }

    /**
     * Retry a failed webhook manually
     */
    public function retryWebhook(MerchantWebhook $webhook): bool
    {
        if ($webhook->status === MerchantWebhook::STATUS_DELIVERED) {
            return true;
        }

        // Reset for retry
        $webhook->update([
            'status' => MerchantWebhook::STATUS_PENDING,
            'next_retry_at' => null,
            'circuit_breaker_active' => false,
        ]);

        return $this->deliver($webhook);
    }

    /**
     * Get pending webhooks for processing
     */
    public function getPendingWebhooks(int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return MerchantWebhook::needsProcessing()
            ->orderByPriority()
            ->limit($limit)
            ->get();
    }
}
