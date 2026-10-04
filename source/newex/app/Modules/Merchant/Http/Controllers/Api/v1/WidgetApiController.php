<?php

namespace App\Modules\Merchant\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Currency\Currency;
use App\Modules\Merchant\Http\Resources\WidgetInvoiceResource;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Services\InvoiceService;
use App\Modules\Merchant\Services\PricingService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WidgetApiController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected PricingService $pricingService
    ) {}

    #[ExcludeRouteFromDocs]
    /**
     * Get invoice for widget display
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInvoice(Request $request): JsonResponse
    {
        $invoice = $this->validateWidgetToken($request);

        if (!$invoice) {
            return $this->errorResponse('INVALID_TOKEN', 'Invalid or expired widget token', 401);
        }

        // Get available currencies with estimated rates
        $availableCurrencies = $this->getAvailableCurrencies($invoice);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => new WidgetInvoiceResource($invoice),
                'available_currencies' => $availableCurrencies,
                'rate_validity_seconds' => config('merchant_acquiring.invoice.rate_validity', 900),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Select currency for payment
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function selectCurrency(Request $request): JsonResponse
    {
        $invoice = $this->validateWidgetToken($request);

        if (!$invoice) {
            return $this->errorResponse('INVALID_TOKEN', 'Invalid or expired widget token', 401);
        }

        $request->validate([
            'currency' => 'required|string|max:50',
            'network_id' => 'nullable|integer',
        ]);

        try {
            $invoice = $this->invoiceService->selectCurrency(
                $invoice,
                $request->get('currency'),
                $request->get('network_id')
            );

            $invoice = $invoice->fresh(['currencyModel']);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice' => new WidgetInvoiceResource($invoice),
                    'payment_details' => [
                        'address' => $invoice->deposit_address,
                        'memo' => $invoice->deposit_memo,
                        'amount_crypto' => $invoice->amount_crypto,
                        'amount_crypto_display' => rtrim(rtrim($invoice->amount_crypto, '0'), '.'),
                        'currency' => $invoice->currencyModel?->symbol,
                        'currency_name' => $invoice->currencyModel?->name,
                        'rate_usd' => $invoice->rate_usd,
                        'rate_expires_at' => $invoice->rate_expires_at?->toIso8601String(),
                        'payment_expires_at' => $invoice->payment_expires_at?->toIso8601String(),
                        'qr_data' => $this->buildQrData($invoice),
                    ],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('SELECTION_FAILED', $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            Log::error('Currency selection runtime error', [
                'invoice_id' => $invoice->id,
                'currency' => $request->get('currency'),
                'network_id' => $request->get('network_id'),
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('RATE_ERROR', $e->getMessage(), 503);
        } catch (\Exception $e) {
            Log::error('Currency selection failed', [
                'invoice_id' => $invoice->id,
                'currency' => $request->get('currency'),
                'network_id' => $request->get('network_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('INTERNAL_ERROR', 'Failed to select currency: ' . $e->getMessage(), 500);
        }
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get invoice status (for polling)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatus(Request $request): JsonResponse
    {
        $invoice = $this->validateWidgetToken($request);

        if (!$invoice) {
            return $this->errorResponse('INVALID_TOKEN', 'Invalid or expired widget token', 401);
        }

        // Check and update expired status if needed
        $invoice->checkAndExpire();

        // Get latest payment info
        $latestPayment = $invoice->latestPayment;

        // Check rate validity
        $rateValid = $invoice->rate_expires_at && $invoice->rate_expires_at->isFuture();
        $rateExpiresIn = $rateValid ? $invoice->rate_expires_at->diffInSeconds(now()) : 0;

        // Payment expiration
        $paymentExpiresIn = $invoice->payment_expires_at?->isFuture()
            ? $invoice->payment_expires_at->diffInSeconds(now())
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $invoice->status,
                'previous_status' => $invoice->previous_status,

                // Amounts
                'amount_usd' => $invoice->amount_usd,
                'amount_crypto' => $invoice->amount_crypto,
                'amount_received_crypto' => $invoice->amount_received_crypto,
                'amount_remaining_crypto' => $invoice->amount_crypto
                    ? bcsub($invoice->amount_crypto, $invoice->amount_received_crypto ?? '0', 18)
                    : null,

                // Payment progress
                'payment_progress_percent' => $invoice->amount_crypto > 0
                    ? min(100, round(($invoice->amount_received_crypto / $invoice->amount_crypto) * 100, 2))
                    : 0,

                // Confirmations
                'confirmations' => $latestPayment?->confirmations ?? 0,
                'required_confirmations' => $invoice->currencyModel?->merchant_confirmations ?? 3,

                // Timing
                'rate_valid' => $rateValid,
                'rate_expires_in_seconds' => $rateExpiresIn,
                'payment_expires_in_seconds' => $paymentExpiresIn,
                'rate_extended_count' => $invoice->rate_extended_count,
                'max_rate_extensions' => config('merchant_acquiring.invoice.max_rate_extensions', 2),

                // Latest transaction
                'latest_transaction' => $latestPayment ? [
                    'txn_hash' => $latestPayment->txn_hash,
                    'amount' => $latestPayment->amount_crypto,
                    'confirmations' => $latestPayment->confirmations,
                    'status' => $latestPayment->status,
                    'explorer_url' => $latestPayment->explorer_url,
                ] : null,

                // Redirect URLs
                'redirect_url' => $invoice->redirect_url,
                'cancel_url' => $invoice->cancel_url,

                // Timestamps
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'expired_at' => $invoice->expired_at?->toIso8601String(),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Extend rate validity
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function extendRate(Request $request): JsonResponse
    {
        $invoice = $this->validateWidgetToken($request);

        if (!$invoice) {
            return $this->errorResponse('INVALID_TOKEN', 'Invalid or expired widget token', 401);
        }

        try {
            $invoice = $this->invoiceService->extendRate($invoice);

            return response()->json([
                'success' => true,
                'data' => [
                    'rate_expires_at' => $invoice->rate_expires_at->toIso8601String(),
                    'rate_extended_count' => $invoice->rate_extended_count,
                    'extensions_remaining' => max(
                        0,
                        config('merchant_acquiring.invoice.max_rate_extensions', 2) - $invoice->rate_extended_count
                    ),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('EXTENSION_FAILED', $e->getMessage(), 400);
        }
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get available currencies for widget
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCurrencies(Request $request): JsonResponse
    {
        $invoice = $this->validateWidgetToken($request);

        if (!$invoice) {
            return $this->errorResponse('INVALID_TOKEN', 'Invalid or expired widget token', 401);
        }

        $currencies = $this->getAvailableCurrencies($invoice);

        return response()->json([
            'success' => true,
            'data' => $currencies,
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Validate widget token and return invoice
     *
     * @param Request $request
     * @return MerchantInvoice|null
     */
    protected function validateWidgetToken(Request $request): ?MerchantInvoice
    {
        $token = $request->bearerToken() ?? $request->get('token');

        if (empty($token) || !str_starts_with($token, 'wgt_')) {
            return null;
        }

        // Remove prefix and split token
        $tokenData = substr($token, 4);
        $parts = explode('.', $tokenData);

        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $encodedPayload, config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(base64_decode($encodedPayload), true);

        if (!$payload || empty($payload['invoice_id'])) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        // Get invoice
        return MerchantInvoice::with(['merchant', 'currencyModel', 'payments'])
            ->find($payload['invoice_id']);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get available currencies with rates
     *
     * @param MerchantInvoice $invoice
     * @return array
     */
    protected function getAvailableCurrencies(MerchantInvoice $invoice): array
    {
        // Get all currencies enabled for merchant acquiring
        $currencies = Currency::where('is_merchant', true)
            ->where('status', true)
            ->with('networks')
            ->orderBy('name')
            ->get();

        $result = [];

        foreach ($currencies as $currency) {
            $rate = $this->pricingService->getEstimatedRate($currency, (float) $invoice->amount_usd);

            // Get enabled networks for this currency's merchant acquiring
            $enabledNetworkIds = $currency->merchant_enabled_networks ?? [];

            // If no specific networks set, use all currency networks
            if (empty($enabledNetworkIds)) {
                $networks = $currency->networks;
            } else {
                $networks = $currency->networks->whereIn('id', $enabledNetworkIds);
            }

            // Create an entry for each network
            foreach ($networks as $network) {
                $result[] = [
                    'symbol' => $currency->symbol,
                    'name' => $currency->name,
                    'type' => $currency->type,
                    'network_id' => $network->id,
                    'network_name' => $network->name,
                    'icon_url' => url($currency->logo_path),
                    'estimated_amount' => $rate['amount_crypto_display'] ?? null,
                    'rate_usd' => $rate['rate_usd'] ?? null,
                    'available' => $rate['available'],
                    'min_amount_usd' => $currency->merchant_min_amount_usd,
                    'max_amount_usd' => $currency->merchant_max_amount_usd,
                    'required_confirmations' => $currency->merchant_confirmations,
                ];
            }
        }

        return $result;
    }

    #[ExcludeRouteFromDocs]
    /**
     * Build QR code data
     *
     * @param MerchantInvoice $invoice
     * @return string|null
     */
    protected function buildQrData(MerchantInvoice $invoice): ?string
    {
        if (!$invoice->deposit_address) {
            return null;
        }

        $currency = $invoice->currencyModel;
        $symbol = strtolower($currency?->symbol ?? '');

        // Build URI based on currency symbol
        return match ($symbol) {
            'btc' => "bitcoin:{$invoice->deposit_address}?amount={$invoice->amount_crypto}",
            'eth' => "ethereum:{$invoice->deposit_address}?value={$invoice->amount_crypto}",
            'ltc' => "litecoin:{$invoice->deposit_address}?amount={$invoice->amount_crypto}",
            default => $invoice->deposit_address,
        };
    }

    #[ExcludeRouteFromDocs]
    /**
     * Return error response
     *
     * @param string $code
     * @param string $message
     * @param int $status
     * @return JsonResponse
     */
    protected function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
