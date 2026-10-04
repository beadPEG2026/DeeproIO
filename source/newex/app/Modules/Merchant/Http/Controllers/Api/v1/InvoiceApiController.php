<?php

namespace App\Modules\Merchant\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Http\Requests\Api\CreateInvoiceRequest;
use App\Modules\Merchant\Http\Requests\Api\ListInvoicesRequest;
use App\Modules\Merchant\Http\Requests\Api\CancelInvoiceRequest;
use App\Modules\Merchant\Http\Requests\Api\RefundInvoiceRequest;
use App\Modules\Merchant\Http\Resources\InvoiceResource;
use App\Modules\Merchant\Http\Resources\InvoiceCollection;
use App\Modules\Merchant\Http\Resources\PaymentResource;
use App\Modules\Merchant\Http\Resources\PaymentCollection;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Repositories\PaymentRepository;
use App\Modules\Merchant\Services\InvoiceService;
use App\Modules\Merchant\Services\RefundService;
use App\Modules\Merchant\Http\Resources\RefundResource;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceApiController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected InvoiceRepository $invoiceRepository,
        protected PaymentRepository $paymentRepository,
        protected RefundService $refundService
    ) {}

    #[ExcludeRouteFromDocs]
    /**
     * Create a new invoice
     *
     * @param CreateInvoiceRequest $request
     * @return JsonResponse
     */
    public function create(CreateInvoiceRequest $request): JsonResponse
    {
        $merchant = $request->getMerchant();

        // Check if merchant can operate
        if (!$merchant->canOperate()) {
            $restriction = $merchant->getOperationRestriction();
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MERCHANT_RESTRICTED',
                    'message' => $restriction['message'] ?? 'Your merchant account is restricted from creating invoices',
                ],
            ], 403);
        }

        $invoice = $this->invoiceService->createInvoice($merchant, [
            'amount' => $request->validated('amount'),
            'currency' => $request->validated('currency', 'USD'),
            'external_id' => $request->validated('external_id'),
            'idempotency_key' => $request->header('Idempotency-Key'),
            'description' => $request->validated('description'),
            'customer_email' => $request->validated('customer_email'),
            'customer_name' => $request->validated('customer_name'),
            'customer_metadata' => $request->validated('customer_metadata'),
            'metadata' => $request->validated('metadata'),
            'line_items' => $request->validated('line_items'),
            'redirect_url' => $request->validated('redirect_url'),
            'cancel_url' => $request->validated('cancel_url'),
            'webhook_url' => $request->validated('webhook_url'),
            'source' => 'api',
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'environment' => $request->getApiKey()->environment,
        ]);

        // Generate widget URL
        $widgetUrl = route('merchant.checkout', ['invoiceId' => $invoice->id]);
        $widgetToken = $this->invoiceService->generateWidgetToken($invoice);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => new InvoiceResource($invoice),
                'checkout_url' => $widgetUrl,
                'widget_token' => $widgetToken,
            ],
        ], 201);
    }

    #[ExcludeRouteFromDocs]
    /**
     * List invoices with filters
     *
     * @param ListInvoicesRequest $request
     * @return JsonResponse
     */
    public function index(ListInvoicesRequest $request): JsonResponse
    {
        $merchant = $request->getMerchant();

        $invoices = $this->invoiceRepository->getForMerchant(
            $merchant->id,
            $request->validated(),
            $request->validated('per_page', 20)
        );

        return response()->json([
            'success' => true,
            'data' => new InvoiceCollection($invoices),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get single invoice
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $merchant = $request->get('merchant');

        $invoice = $this->invoiceService->getInvoice($id, $merchant->id);

        return response()->json([
            'success' => true,
            'data' => new InvoiceResource($invoice->load(['payments', 'currencyModel', 'network', 'timeline'])),
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get invoice status (lightweight endpoint)
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $merchant = $request->get('merchant');

        $invoice = $this->invoiceService->getInvoice($id, $merchant->id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $invoice->id,
                'status' => $invoice->status,
                'amount_usd' => $invoice->amount_usd,
                'amount_crypto' => $invoice->amount_crypto,
                'amount_received_crypto' => $invoice->amount_received_crypto,
                'amount_received_usd' => $invoice->amount_received_usd,
                'confirmations' => $invoice->payments()->sum('confirmations'),
                'required_confirmations' => $invoice->currencyModel?->merchant_confirmations ?? 3,
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'expires_at' => $invoice->expires_at?->toIso8601String(),
                'payment_expires_at' => $invoice->payment_expires_at?->toIso8601String(),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Cancel an invoice
     *
     * @param CancelInvoiceRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function cancel(CancelInvoiceRequest $request, string $id): JsonResponse
    {
        $merchant = $request->getMerchant();

        $invoice = $this->invoiceService->getInvoice($id, $merchant->id);

        $this->invoiceService->cancelInvoice(
            $invoice,
            $request->validated('reason'),
            $request->get('api_key')->id
        );

        return response()->json([
            'success' => true,
            'data' => new InvoiceResource($invoice->fresh()),
            'message' => 'Invoice cancelled successfully',
        ]);
    }

    /**
     * Initiate refund for an invoice
     *
     * @param RefundInvoiceRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function refund(RefundInvoiceRequest $request, string $id): JsonResponse
    {
        $merchant = $request->getMerchant();

        $invoice = $this->invoiceService->getInvoice($id, $merchant->id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_NOT_FOUND',
                    'message' => 'Invoice not found',
                ],
            ], 404);
        }

        $validated = $request->validated();

        // Destination address is required for refunds
        if (empty($validated['destination_address'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DESTINATION_ADDRESS_REQUIRED',
                    'message' => 'Destination address is required for refunds',
                ],
            ], 422);
        }

        try {
            $refund = $this->refundService->initiateRefund(
                $invoice,
                $validated['destination_address'],
                $validated['amount'] ?? null,
                $validated['reason'] ?? null,
                null,
                auth()->id(),
                false
            );

            return response()->json([
                'success' => true,
                'message' => 'Refund initiated successfully',
                'data' => [
                    'invoice_id' => $invoice->id,
                    'refund_id' => $refund->id,
                    'refund_status' => $refund->status,
                    'refund_type' => $refund->refund_type,
                    'amount_crypto' => $refund->amount_crypto,
                    'amount_usd' => $refund->amount_usd,
                    'destination_address' => $refund->destination_address,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REFUND_INVALID',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REFUND_FAILED',
                    'message' => 'Failed to initiate refund: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    #[ExcludeRouteFromDocs]
    /**
     * List payments for merchant
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listPayments(Request $request): JsonResponse
    {
        $merchant = $request->get('merchant');

        $payments = $this->paymentRepository->getForMerchant(
            $merchant->id,
            $request->only(['invoice_id', 'status', 'txn_hash', 'detected_from', 'detected_to']),
            $request->get('per_page', 20)
        );

        return response()->json([
            'success' => true,
            'data' => new PaymentCollection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Get single payment
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function showPayment(Request $request, string $id): JsonResponse
    {
        $merchant = $request->get('merchant');

        $payment = $this->paymentRepository->find($id);

        if (!$payment || $payment->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Payment not found',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PaymentResource($payment->load('invoice')),
        ]);
    }
}
