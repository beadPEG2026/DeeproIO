<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Services\InvoiceService;
use App\Modules\Merchant\Services\MerchantAuthService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API endpoints for the Merchant Dashboard (session auth)
 * These endpoints are called by Vue components in the merchant dashboard.
 */
class MerchantDashboardApiController extends Controller
{
    public function __construct(
        protected MerchantAuthService $authService,
        protected InvoiceService $invoiceService
    ) {}

    #[ExcludeRouteFromDocs]
    /**
     * Get the current merchant
     */
    protected function getMerchant(): ?Merchant
    {
        return Merchant::where('user_id', auth()->id())->first();
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update merchant settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Merchant account not found'],
            ], 404);
        }

        $validated = $request->validate([
            'business_name' => 'sometimes|string|max:255',
            'business_email' => 'sometimes|email|max:255',
            'website_url' => 'nullable|url|max:255',
            'default_webhook_url' => 'nullable|url|max:500',
        ]);

        $merchant->update($validated);

        return response()->json([
            'success' => true,
            'data' => ['merchant' => $merchant->fresh()],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Rotate webhook secret
     */
    public function rotateWebhookSecret(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Merchant account not found'],
            ], 404);
        }

        $newSecret = 'whsec_' . Str::random(32);
        $merchant->update(['webhook_secret' => $newSecret]);

        return response()->json([
            'success' => true,
            'data' => ['webhook_secret' => $newSecret],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Create API key
     */
    public function createApiKey(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Merchant account not found'],
            ], 404);
        }

        // Check if merchant can operate
        if (!$merchant->canOperate()) {
            $restriction = $merchant->getOperationRestriction();
            return response()->json([
                'success' => false,
                'error' => ['code' => 'OPERATION_RESTRICTED', 'message' => $restriction['message'] ?? 'Your account is restricted from creating API keys'],
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'environment' => 'in:live,test',
            'permissions' => 'array',
            'ip_whitelist' => 'nullable|array',
        ]);

        $result = $this->authService->generateApiKey(
            $merchant,
            $validated['name'],
            $validated['environment'] ?? 'live',
            $validated['permissions'] ?? []
        );

        if (!empty($validated['ip_whitelist'])) {
            $result['api_key']->update([
                'ip_whitelist' => $validated['ip_whitelist'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'api_key' => $result['api_key'],
                'public_key' => $result['public_key'],
                'secret_key' => $result['secret_key'],
            ],
        ], 201);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Delete/Revoke API key
     */
    public function deleteApiKey(Request $request, string $id): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Merchant account not found'],
            ], 404);
        }

        $apiKey = $merchant->apiKeys()->find($id);

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'API key not found'],
            ], 404);
        }

        $this->authService->revokeApiKey($apiKey);

        return response()->json([
            'success' => true,
            'message' => 'API key revoked successfully',
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Create invoice from dashboard
     */
    public function createInvoice(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Merchant account not found'],
            ], 404);
        }

        // Check if merchant can operate
        if (!$merchant->canOperate()) {
            $restriction = $merchant->getOperationRestriction();
            return response()->json([
                'success' => false,
                'error' => ['code' => 'OPERATION_RESTRICTED', 'message' => $restriction['message'] ?? 'Your account is restricted from creating invoices'],
            ], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:500',
            'external_id' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_name' => 'nullable|string|max:255',
            'redirect_url' => 'nullable|url|max:500',
            'webhook_url' => 'nullable|url|max:500',
            'metadata' => 'nullable|array',
        ]);

        try {
            $invoice = $this->invoiceService->createInvoice($merchant, array_merge($validated, [
                'source' => 'dashboard',
                'environment' => 'live',
            ]));

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice' => $invoice,
                    'checkout_url' => route('merchant.checkout', ['invoiceId' => $invoice->id]),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREATE_FAILED', 'message' => $e->getMessage()],
            ], 400);
        }
    }

    #[ExcludeRouteFromDocs]
    /**
     * Cancel invoice from dashboard
     */
    public function cancelInvoice(Request $request, string $id): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Merchant account not found'],
            ], 404);
        }

        $invoice = $merchant->invoices()->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Invoice not found'],
            ], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->invoiceService->cancelInvoice($invoice, $validated['reason'] ?? 'Cancelled by merchant');

            return response()->json([
                'success' => true,
                'data' => ['invoice' => $invoice->fresh()],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CANCEL_FAILED', 'message' => $e->getMessage()],
            ], 400);
        }
    }
}
