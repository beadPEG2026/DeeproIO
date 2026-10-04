<?php

namespace App\Modules\Merchant\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Http\Resources\MerchantResource;
use App\Modules\Merchant\Http\Resources\ApiKeyResource;
use App\Modules\Merchant\Http\Resources\ApiKeyCollection;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Services\MerchantAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;

class MerchantApiController extends Controller
{
    public function __construct(
        protected MerchantAuthService $authService,
        protected InvoiceRepository $invoiceRepository
    ) {}


    #[ExcludeRouteFromDocs]
    /**
     * Get merchant account details
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        return response()->json([
            'success' => true,
            'data' => new MerchantResource($merchant),
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get merchant balance
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getBalance(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        // Calculate available balance
        $balance = [
            'available_usd' => 0,
            'pending_usd' => 0,
            'total_volume_usd' => $merchant->total_volume_usd,
            'today_volume_usd' => $this->invoiceRepository->getDailyVolume($merchant->id),
            'month_volume_usd' => $this->invoiceRepository->getMonthlyVolume($merchant->id),
            'daily_limit_usd' => $merchant->daily_volume_limit_usd,
            'daily_limit_remaining_usd' => max(
                0,
                $merchant->daily_volume_limit_usd - $this->invoiceRepository->getDailyVolume($merchant->id)
            ),
        ];

        return response()->json([
            'success' => true,
            'data' => $balance,
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * List API keys
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listApiKeys(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        $apiKeys = $merchant->apiKeys()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => new ApiKeyCollection($apiKeys),
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Create new API key
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createApiKey(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'environment' => 'in:live,test',
            'permissions' => 'array',
            'permissions.*' => 'string',
            'ip_whitelist' => 'array',
            'ip_whitelist.*' => 'string',
        ]);

        $merchant = $request->get('merchant');
        $currentApiKey = $request->get('api_key');

        // Check if current API key has permission to create keys
        if (!$currentApiKey->hasPermission('api_keys:write')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PERMISSION_DENIED',
                    'message' => 'This API key does not have permission to create new keys',
                ],
            ], 403);
        }

        $result = $this->authService->generateApiKey(
            $merchant,
            $request->get('name'),
            $request->get('environment', 'live'),
            $request->get('permissions', []),
            $currentApiKey->id
        );

        // Update with IP whitelist if provided
        if ($request->has('ip_whitelist')) {
            $result['api_key']->update([
                'ip_whitelist' => $request->get('ip_whitelist'),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'api_key' => new ApiKeyResource($result['api_key']),
                'public_key' => $result['public_key'],
                'secret_key' => $result['secret_key'], // Only shown once!
            ],
            'warning' => 'Store the secret_key securely. It will not be shown again.',
        ], 201);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Revoke an API key
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function revokeApiKey(Request $request, string $id): JsonResponse
    {
        $merchant = $request->get('merchant');
        $currentApiKey = $request->get('api_key');

        // Check permission
        if (!$currentApiKey->hasPermission('api_keys:write')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PERMISSION_DENIED',
                    'message' => 'This API key does not have permission to revoke keys',
                ],
            ], 403);
        }

        $apiKey = $merchant->apiKeys()->find($id);

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'API key not found',
                ],
            ], 404);
        }

        // Prevent revoking own key
        if ($apiKey->id === $currentApiKey->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CANNOT_REVOKE_SELF',
                    'message' => 'Cannot revoke the API key being used for this request',
                ],
            ], 400);
        }

        $this->authService->revokeApiKey(
            $apiKey,
            $currentApiKey->id,
            $request->get('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'API key revoked successfully',
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Rotate webhook secret
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function rotateWebhookSecret(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');
        $currentApiKey = $request->get('api_key');

        // Check permission
        if (!$currentApiKey->hasPermission('settings:write')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PERMISSION_DENIED',
                    'message' => 'This API key does not have permission to modify settings',
                ],
            ], 403);
        }

        $oldSecret = $merchant->webhook_secret;
        $newSecret = $merchant->generateWebhookSecret();

        return response()->json([
            'success' => true,
            'data' => [
                'webhook_secret' => $newSecret,
            ],
            'warning' => 'Update your webhook handler immediately. The old secret is now invalid.',
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get merchant statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        $stats = $this->invoiceRepository->getStatistics(
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
     * Get rate limit status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function rateLimitStatus(Request $request): JsonResponse
    {
        $apiKey = $request->get('api_key');

        $status = $this->authService->getRateLimitStatus($apiKey);

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }
}
