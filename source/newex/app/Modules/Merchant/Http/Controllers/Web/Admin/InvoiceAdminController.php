<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Enums\InvoiceStatus;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceAdminController extends Controller
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository
    ) {}

    /**
     * List all invoices
     */
    public function index(Request $request): Response
    {
        $query = MerchantInvoice::with(['merchant', 'currencyModel', 'network']);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('deposit_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        if ($request->filled('amount_min')) {
            $query->where('amount_usd', '>=', $request->amount_min);
        }

        if ($request->filled('amount_max')) {
            $query->where('amount_usd', '<=', $request->amount_max);
        }

        // Sort
        $sortField = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $invoices = $query->paginate($request->get('per_page', 20));

        // Stats
        $stats = [
            'total' => MerchantInvoice::count(),
            'paid_today' => MerchantInvoice::whereDate('paid_at', today())->count(),
            'volume_today_usd' => MerchantInvoice::whereDate('paid_at', today())->sum('amount_usd'),
            'pending' => MerchantInvoice::pending()->count(),
        ];

        return Inertia::render('Admin/Merchant/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'merchant_id', 'search', 'date_from', 'date_to', 'amount_min', 'amount_max']),
            'stats' => $stats,
            'statuses' => collect(InvoiceStatus::cases())->map(fn($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }

    /**
     * Show invoice details
     */
    public function show(string $id): Response
    {
        $invoice = MerchantInvoice::with([
            'merchant',
            'currencyModel',
            'network',
            'payments',
            'webhooks.attempts',
            'timeline',
            'refunds',
            'depositAddress',
        ])->findOrFail($id);

        return Inertia::render('Admin/Merchant/Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Force expire an invoice
     */
    public function forceExpire(Request $request, string $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = MerchantInvoice::findOrFail($id);

        if ($invoice->isFinal()) {
            return redirect()->back()->with('error', 'Invoice is already in a final state');
        }

        app(\App\Modules\Merchant\Services\InvoiceService::class)->expireInvoice(
            $invoice,
            'Admin: ' . $request->reason
        );

        return redirect()->back()->with('success', 'Invoice expired');
    }

    /**
     * Force settle an invoice
     */
    public function forceSettle(Request $request, string $id)
    {
        $invoice = MerchantInvoice::findOrFail($id);

        if (!in_array($invoice->status, [InvoiceStatus::PAID->value, InvoiceStatus::OVERPAID->value])) {
            return redirect()->back()->with('error', 'Invoice must be in paid state to settle');
        }

        app(\App\Modules\Merchant\Services\InvoiceService::class)->settleInvoice($invoice);

        return redirect()->back()->with('success', 'Invoice settled');
    }
}
