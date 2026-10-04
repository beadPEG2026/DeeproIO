<?php

namespace App\Modules\Merchant\Tests\Feature;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use App\Modules\Merchant\Jobs\ProcessWebhookDeliveryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;
    protected MerchantInvoice $invoice;
    protected WebhookDispatcherService $webhookService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create([
            'status' => 'active',
            'default_webhook_url' => 'https://merchant.example.com/webhooks',
            'webhook_secret' => 'whsec_test_secret_key',
        ]);

        $this->invoice = MerchantInvoice::factory()->create([
            'merchant_id' => $this->merchant->id,
            'status' => 'paid',
        ]);

        $this->webhookService = app(WebhookDispatcherService::class);
    }

    /** @test */
    public function webhook_is_queued_on_invoice_status_change()
    {
        Queue::fake();

        $this->webhookService->dispatch(
            $this->merchant,
            'invoice.paid',
            ['invoice' => $this->invoice->toArray()],
            $this->invoice->id
        );

        $this->assertDatabaseHas('merchant_webhooks', [
            'merchant_id' => $this->merchant->id,
            'event_type' => 'invoice.paid',
            'invoice_id' => $this->invoice->id,
        ]);

        Queue::assertPushed(ProcessWebhookDeliveryJob::class);
    }

    /** @test */
    public function webhook_delivery_success()
    {
        Http::fake([
            'merchant.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $webhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'invoice_id' => $this->invoice->id,
            'event_type' => 'invoice.paid',
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'payload' => ['invoice' => $this->invoice->toArray()],
            'status' => 'pending',
        ]);

        $result = $this->webhookService->deliver($webhook);

        $this->assertTrue($result);

        $webhook->refresh();
        $this->assertEquals('delivered', $webhook->status);
        $this->assertEquals(200, $webhook->last_response_code);
        $this->assertNotNull($webhook->delivered_at);
    }

    /** @test */
    public function webhook_delivery_failure_triggers_retry()
    {
        Http::fake([
            'merchant.example.com/*' => Http::response('Server Error', 500),
        ]);

        $webhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'invoice_id' => $this->invoice->id,
            'event_type' => 'invoice.paid',
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'payload' => ['invoice' => $this->invoice->toArray()],
            'status' => 'pending',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        $result = $this->webhookService->deliver($webhook);

        $this->assertFalse($result);

        $webhook->refresh();
        $this->assertEquals('pending_retry', $webhook->status);
        $this->assertEquals(1, $webhook->attempt_count);
        $this->assertEquals(500, $webhook->last_response_code);
        $this->assertNotNull($webhook->next_retry_at);
    }

    /** @test */
    public function webhook_marked_failed_after_max_attempts()
    {
        Http::fake([
            'merchant.example.com/*' => Http::response('Server Error', 500),
        ]);

        $webhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'invoice_id' => $this->invoice->id,
            'event_type' => 'invoice.paid',
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'payload' => ['invoice' => $this->invoice->toArray()],
            'status' => 'pending_retry',
            'attempt_count' => 4, // 5th attempt will be the last
            'max_attempts' => 5,
        ]);

        $result = $this->webhookService->deliver($webhook);

        $this->assertFalse($result);

        $webhook->refresh();
        $this->assertEquals('failed', $webhook->status);
        $this->assertEquals(5, $webhook->attempt_count);
    }

    /** @test */
    public function webhook_signature_is_correctly_generated()
    {
        Http::fake([
            'merchant.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $webhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'payload' => ['test' => 'data'],
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'status' => 'pending',
        ]);

        $this->webhookService->deliver($webhook);

        Http::assertSent(function ($request) {
            $signature = $request->header('X-Webhook-Signature')[0] ?? '';
            $timestamp = $request->header('X-Webhook-Timestamp')[0] ?? '';

            // Verify signature format
            $this->assertStringStartsWith('v1=', $signature);
            $this->assertNotEmpty($timestamp);

            return true;
        });
    }

    /** @test */
    public function idempotency_key_is_included_in_webhook()
    {
        Http::fake([
            'merchant.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $webhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'idempotency_key' => 'test_idempotency_123',
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'payload' => ['test' => 'data'],
            'status' => 'pending',
        ]);

        $this->webhookService->deliver($webhook);

        Http::assertSent(function ($request) use ($webhook) {
            $idempotencyKey = $request->header('X-Idempotency-Key')[0] ?? '';
            return $idempotencyKey === $webhook->idempotency_key;
        });
    }

    /** @test */
    public function circuit_breaker_opens_after_consecutive_failures()
    {
        Http::fake([
            'merchant.example.com/*' => Http::response('Server Error', 500),
        ]);

        // Simulate multiple failures to trigger circuit breaker
        for ($i = 0; $i < 10; $i++) {
            $webhook = MerchantWebhook::factory()->create([
                'merchant_id' => $this->merchant->id,
                'webhook_url' => 'https://merchant.example.com/webhooks',
                'payload' => ['test' => 'data'],
                'status' => 'pending',
            ]);

            $this->webhookService->deliver($webhook);
        }

        // New webhook should be delayed due to circuit breaker
        $newWebhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'payload' => ['test' => 'data'],
            'status' => 'pending',
        ]);

        $result = $this->webhookService->deliver($newWebhook);

        // Should return false due to circuit breaker
        $this->assertFalse($result);
    }

    /** @test */
    public function webhook_timeout_handling()
    {
        Http::fake([
            'merchant.example.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $webhook = MerchantWebhook::factory()->create([
            'merchant_id' => $this->merchant->id,
            'webhook_url' => 'https://merchant.example.com/webhooks',
            'payload' => ['test' => 'data'],
            'status' => 'pending',
        ]);

        $result = $this->webhookService->deliver($webhook);

        $this->assertFalse($result);

        $webhook->refresh();
        $this->assertEquals('pending_retry', $webhook->status);
        $this->assertStringContainsString('timeout', strtolower($webhook->last_error ?? ''));
    }
}
