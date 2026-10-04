<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantOrphanPayment;
use App\Modules\Merchant\Models\MerchantRefund;
use App\Modules\Merchant\Services\RefundService;
use App\Modules\Merchant\Services\RefundTransferService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RefundAdminController extends Controller
{
    public function __construct(
        protected RefundService $refundService,
        protected RefundTransferService $refundTransferService
    ) {}

    /**
     * Refunds management page
     */
    public function index(Request $request)
    {
        $query = MerchantRefund::with(['merchant', 'invoice', 'invoice.currencyModel', 'orphanPayment', 'approvedByUser']);

        // Apply filters
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('refund_type')) {
            $query->where('refund_type', $request->refund_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $refunds = $query->orderBy('created_at', 'desc')->paginate(30);

        // Stats
        $refundStats = [
            'pending_count' => MerchantRefund::where('status', MerchantRefund::STATUS_PENDING)->count(),
            'pending_amount' => MerchantRefund::where('status', MerchantRefund::STATUS_PENDING)->sum('amount_usd'),
            'processing_count' => MerchantRefund::where('status', MerchantRefund::STATUS_PROCESSING)->count(),
            'processing_amount' => MerchantRefund::where('status', MerchantRefund::STATUS_PROCESSING)->sum('amount_usd'),
            'completed_count' => MerchantRefund::where('status', MerchantRefund::STATUS_COMPLETED)->count(),
            'completed_amount' => MerchantRefund::where('status', MerchantRefund::STATUS_COMPLETED)->sum('amount_usd'),
            'failed_count' => MerchantRefund::where('status', MerchantRefund::STATUS_FAILED)->count(),
            'failed_amount' => MerchantRefund::where('status', MerchantRefund::STATUS_FAILED)->sum('amount_usd'),
        ];

        // Merchants for filter dropdown
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);

        return Inertia::render('Admin/Merchant/Refunds/Index', [
            'refunds' => $refunds,
            'stats' => $refundStats,
            'merchants' => $merchants,
            'filters' => $request->only(['merchant_id', 'status', 'refund_type', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Show single refund details
     */
    public function show(string $id): Response
    {
        $refund = MerchantRefund::with([
            'merchant',
            'invoice',
            'invoice.currencyModel',
            'invoice.networkModel',
            'payment',
            'orphanPayment',
            'requestedByUser',
            'approvedByUser',
        ])->findOrFail($id);

        return Inertia::render('Admin/Merchant/Refunds/Show', [
            'refund' => $refund,
        ]);
    }

    /**
     * Approve a pending refund
     */
    public function approve(Request $request, string $id)
    {
        $refund = MerchantRefund::findOrFail($id);

        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'send_immediately' => 'nullable|boolean',
        ]);

        try {
            $this->refundService->approveRefund($refund, auth()->id(), $request->notes);

            // If send_immediately is true, process the refund right away
            if ($request->boolean('send_immediately')) {
                $refund = $refund->fresh();
                $result = $this->refundService->processRefund($refund);

                if ($result['success']) {
                    return redirect()->back()->with('success', 'Refund approved and sent successfully. TX: ' . $result['txn_hash']);
                } else {
                    return redirect()->back()->with('error', 'Refund approved but sending failed: ' . $result['error']);
                }
            }

            return redirect()->back()->with('success', 'Refund approved successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Process and send an approved refund
     */
    public function process(Request $request, string $id)
    {
        $refund = MerchantRefund::findOrFail($id);

        if ($refund->status !== MerchantRefund::STATUS_PROCESSING) {
            return redirect()->back()->with('error', 'Refund must be in processing status');
        }

        try {
            $result = $this->refundService->processRefund($refund);

            if ($result['success']) {
                return redirect()->back()->with('success', 'Refund sent successfully. TX: ' . $result['txn_hash']);
            } else {
                return redirect()->back()->with('error', 'Refund failed: ' . $result['error']);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error processing refund: ' . $e->getMessage());
        }
    }

    /**
     * Retry a failed refund
     */
    public function retry(Request $request, string $id)
    {
        $refund = MerchantRefund::findOrFail($id);

        if ($refund->status !== MerchantRefund::STATUS_FAILED) {
            return redirect()->back()->with('error', 'Only failed refunds can be retried');
        }

        try {
            $result = $this->refundTransferService->retryRefund($refund);

            if ($result['success']) {
                return redirect()->back()->with('success', 'Refund retry successful. TX: ' . $result['txn_hash']);
            } else {
                return redirect()->back()->with('error', 'Refund retry failed: ' . $result['error']);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error retrying refund: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a pending refund
     */
    public function cancel(Request $request, string $id)
    {
        $refund = MerchantRefund::findOrFail($id);

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->refundService->cancelRefund($refund, $request->reason);

            return redirect()->back()->with('success', 'Refund cancelled successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Mark refund as completed (after manual transfer)
     */
    public function complete(Request $request, string $id)
    {
        throw \Illuminate\Validation\ValidationException::withMessages(['txn_hash' => __('Manual merchant completion requires a verified receipt adapter; use the tracked processing flow.')]);
    }

    /**
     * Initiate refund for orphan payment
     */
    public function refundOrphanPayment(Request $request, string $orphanId)
    {
        $orphanPayment = MerchantOrphanPayment::findOrFail($orphanId);

        $request->validate([
            'destination_address' => 'required|string|max:255',
        ]);

        try {
            $refund = $this->refundService->initiateOrphanPaymentRefund(
                $orphanPayment,
                $request->destination_address,
                auth()->id()
            );

            return redirect()->back()->with('success', 'Refund initiated for orphan payment. ID: ' . $refund->id);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
