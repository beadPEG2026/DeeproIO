<?php

namespace App\Modules\Merchant\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Enums\WebhookEventType;
use App\Modules\Merchant\Http\Resources\WebhookResource;
use App\Modules\Merchant\Http\Resources\WebhookCollection;
use App\Modules\Merchant\Repositories\WebhookRepository;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookApiController extends Controller
{
    public function __construct(
        protected WebhookRepository $webhookRepository,
        protected WebhookDispatcherService $webhookDispatcher
    ) {}

    #[ExcludeRouteFromDocs]
    /**
     * List webhooks with filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        $webhooks = $this->webhookRepository->getForMerchant(
            $merchant->id,
            $request->only(['status', 'event_type', 'invoice_id', 'created_from', 'created_to']),
            $request->get('per_page', 20)
        );

        return response()->json([
            'success' => true,
            'data' => new WebhookCollection($webhooks),
            'meta' => [
                'current_page' => $webhooks->currentPage(),
                'last_page' => $webhooks->lastPage(),
                'per_page' => $webhooks->perPage(),
                'total' => $webhooks->total(),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get single webhook details
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $merchant = $request->get('merchant');

        $webhook = $this->webhookRepository->findForMerchant($id, $merchant->id);

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Webhook not found',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new WebhookResource($webhook->load('attempts')),
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Retry a failed webhook
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        $merchant = $request->get('merchant');

        $webhook = $this->webhookRepository->findForMerchant($id, $merchant->id);

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Webhook not found',
                ],
            ], 404);
        }

        if ($webhook->status === 'delivered') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ALREADY_DELIVERED',
                    'message' => 'Webhook was already delivered successfully',
                ],
            ], 400);
        }

        $success = $this->webhookDispatcher->retryWebhook($webhook);

        return response()->json([
            'success' => true,
            'data' => [
                'webhook_id' => $webhook->id,
                'retry_initiated' => true,
                'immediate_success' => $success,
                'new_status' => $webhook->fresh()->status,
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Send a test webhook
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendTest(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        $webhookUrl = $request->get('url', $merchant->default_webhook_url);

        if (empty($webhookUrl)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NO_WEBHOOK_URL',
                    'message' => 'No webhook URL provided or configured',
                ],
            ], 400);
        }

        // Create test payload
        $testPayload = [
            'id' => 'whk_test_' . now()->timestamp,
            'timestamp' => now()->toIso8601String(),
            'api_version' => config('merchant_acquiring.api_version', '2024-01-01'),
            'event' => [
                'type' => 'test',
                'created_at' => now()->toIso8601String(),
            ],
            'data' => [
                'object' => 'test',
                'message' => 'This is a test webhook from your Crypto Acquiring integration',
                'merchant_id' => $merchant->id,
            ],
        ];

        // Generate signature
        $timestamp = now()->timestamp;
        $signature = $this->webhookDispatcher->generateSignature(
            $testPayload,
            $timestamp,
            $merchant->webhook_secret
        );

        // Send webhook synchronously for immediate feedback
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'CryptoAcquiring-Webhook/1.0',
                    'X-Webhook-Id' => $testPayload['id'],
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => $signature,
                    'X-Event-Type' => 'test',
                ])
                ->post($webhookUrl, $testPayload);

            return response()->json([
                'success' => true,
                'data' => [
                    'webhook_url' => $webhookUrl,
                    'response_code' => $response->status(),
                    'response_successful' => $response->successful(),
                    'response_time_ms' => null, // Would need to measure
                    'payload_sent' => $testPayload,
                    'signature_header' => $signature,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELIVERY_FAILED',
                    'message' => 'Failed to deliver test webhook: ' . $e->getMessage(),
                    'webhook_url' => $webhookUrl,
                ],
            ], 502);
        }
    }

    #[ExcludeRouteFromDocs]
    /**
     * Verify a webhook signature
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verify(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $secret = $request->get('secret');

        if (empty($signature) || empty($timestamp) || empty($secret)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MISSING_PARAMS',
                    'message' => 'Missing signature, timestamp, or secret',
                ],
            ], 400);
        }

        $isValid = $this->webhookDispatcher->verifySignature(
            $payload,
            $signature,
            (int) $timestamp,
            $secret
        );

        return response()->json([
            'success' => true,
            'data' => [
                'valid' => $isValid,
                'timestamp_valid' => abs(time() - (int) $timestamp) <= 300,
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get webhook statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        $stats = $this->webhookRepository->getStatistics(
            $merchant->id,
            $request->get('date_from'),
            $request->get('date_to')
        );

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * List available webhook event types
     *
     * @return JsonResponse
     */
    public function eventTypes(): JsonResponse
    {
        $eventTypes = collect(WebhookEventType::cases())->map(function ($event) {
            return [
                'type' => $event->value,
                'label' => $event->label(),
                'description' => $event->description(),
                'priority' => $event->priority()->value,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $eventTypes,
        ]);
    }
}
