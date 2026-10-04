<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Repositories\WebhookRepository;
use App\Modules\Merchant\Services\MerchantNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MerchantDashboardController extends Controller
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected WebhookRepository $webhookRepository,
        protected MerchantNotificationService $notificationService
    ) {}

    /**
     * Get the current merchant
     */
    protected function getMerchant(): ?Merchant
    {
        return Merchant::where('user_id', auth()->id())->first();
    }

    /**
     * Show merchant application form
     */
    public function showApplication(): Response|RedirectResponse
    {
        $merchant = $this->getMerchant();

        // If already a merchant and not rejected, redirect to dashboard
        if ($merchant && !$merchant->isRejected()) {
            return redirect()->route('merchant.dashboard');
        }

        // If rejected, show resubmission form with rejection reason
        if ($merchant && $merchant->isRejected()) {
            return Inertia::render('Merchant/Onboarding', [
                'merchant' => $merchant,
                'isResubmission' => true,
                'rejectionReason' => $merchant->rejection_reason,
                'submissionCount' => $merchant->submission_count,
            ]);
        }

        return Inertia::render('Merchant/Onboarding');
    }

    /**
     * Submit merchant application
     */
    public function submitApplication(Request $request): JsonResponse|RedirectResponse
    {
        $merchant = $this->getMerchant();

        // If merchant exists and is not rejected, return error
        if ($merchant && !$merchant->isRejected()) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'You already have a merchant account.']
            ], 400);
        }

        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'business_email' => 'required|email|max:255',
            'website_url' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:2000',
            'country_code' => 'nullable|string|max:3',
            'agree_terms' => 'required|accepted',
        ]);

        // If resubmitting after rejection
        if ($merchant && $merchant->isRejected()) {
            $merchant->resubmit([
                'business_name' => $validated['business_name'],
                'business_email' => $validated['business_email'],
                'business_website' => $validated['website_url'] ?? null,
                'business_description' => $validated['description'] ?? null,
                'country_code' => $validated['country_code'] ?? null,
            ]);

            // Send admin notification about resubmission
            $this->notificationService->notifyMerchantApplicationSubmitted($merchant->fresh());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'data' => ['merchant' => $merchant->fresh()],
                    'message' => 'Application resubmitted successfully.',
                ]);
            }

            return redirect()->route('merchant.dashboard');
        }

        // Create new merchant record
        $merchant = Merchant::create([
            'id' => Str::uuid()->toString(),
            'user_id' => auth()->id(),
            'business_name' => $validated['business_name'],
            'business_email' => $validated['business_email'],
            'business_website' => $validated['website_url'] ?? null,
            'business_description' => $validated['description'] ?? null,
            'country_code' => $validated['country_code'] ?? null,
            'status' => 'pending',
            'verification_status' => 'pending',
            'webhook_secret' => 'whsec_' . Str::random(32),
            'fee_percent' => config('merchant_acquiring.merchant.default_fee_percent', 1.50),
            'daily_volume_limit_usd' => config('merchant_acquiring.merchant.default_daily_limit_usd', 50000),
            'monthly_volume_limit_usd' => config('merchant_acquiring.merchant.default_monthly_limit_usd', 500000),
            'single_invoice_limit_usd' => config('merchant_acquiring.merchant.default_single_invoice_limit_usd', 5000),
            'min_invoice_amount_usd' => config('merchant_acquiring.invoice.min_amount_usd', 1.00),
            'submission_count' => 1,
            'last_submitted_at' => now(),
        ]);

        // Send admin notification about new application
        $this->notificationService->notifyMerchantApplicationSubmitted($merchant);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'data' => ['merchant' => $merchant],
            ]);
        }

        return redirect()->route('merchant.dashboard');
    }

    /**
     * Dashboard overview
     */
    public function index(): Response
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return Inertia::render('Merchant/Onboarding');
        }

        // If rejected, show rejection notice with option to resubmit
        if ($merchant->isRejected()) {
            return Inertia::render('Merchant/Rejected', [
                'merchant' => $merchant,
                'rejectionReason' => $merchant->rejection_reason,
                'rejectedAt' => $merchant->rejected_at?->toIso8601String(),
                'submissionCount' => $merchant->submission_count,
            ]);
        }

        // If pending, show pending status
        if ($merchant->isPending()) {
            return Inertia::render('Merchant/Pending', [
                'merchant' => $merchant,
                'submittedAt' => $merchant->last_submitted_at?->toIso8601String() ?? $merchant->created_at->toIso8601String(),
                'submissionCount' => $merchant->submission_count,
            ]);
        }

        $stats = $this->invoiceRepository->getStatistics($merchant->id);
        $recentInvoices = $this->invoiceRepository->getRecentInvoices($merchant->id, 5);
        $dailyVolume = $this->invoiceRepository->getDailyVolume($merchant->id);
        $monthlyVolume = $this->invoiceRepository->getMonthlyVolume($merchant->id);

        return Inertia::render('Merchant/Dashboard', [
            'merchant' => $merchant,
            'stats' => $stats,
            'recentInvoices' => $recentInvoices,
            'dailyVolume' => $dailyVolume,
            'monthlyVolume' => $monthlyVolume,
            'limits' => [
                'daily_limit_usd' => $merchant->daily_volume_limit_usd,
                'daily_remaining_usd' => max(0, $merchant->daily_volume_limit_usd - $dailyVolume),
            ],
        ]);
    }

    /**
     * List invoices
     */
    public function invoices(Request $request): Response
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return redirect()->route('merchant.dashboard');
        }

        $invoices = $this->invoiceRepository->getForMerchant(
            $merchant->id,
            $request->only(['status', 'external_id', 'customer_email', 'created_from', 'created_to']),
            $request->get('per_page', 20)
        );

        return Inertia::render('Merchant/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'external_id', 'customer_email', 'created_from', 'created_to']),
            'stats' => $this->invoiceRepository->countByStatus($merchant->id),
        ]);
    }

    /**
     * Show single invoice
     */
    public function showInvoice(string $id): Response
    {
        $merchant = $this->getMerchant();

        $invoice = $this->invoiceRepository->findForMerchant($id, $merchant->id);

        if (!$invoice) {
            abort(404);
        }

        $invoice->load(['payments', 'webhooks', 'timeline', 'currencyModel', 'network']);

        return Inertia::render('Merchant/Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Create invoice form
     */
    public function createInvoice(): Response
    {
        $merchant = $this->getMerchant();

        return Inertia::render('Merchant/Invoices/Create', [
            'merchant' => $merchant,
            'limits' => [
                'min_amount_usd' => $merchant->min_invoice_amount_usd,
                'max_amount_usd' => $merchant->single_invoice_limit_usd,
            ],
            'canOperate' => $merchant->canOperate(),
            'operationRestriction' => $merchant->getOperationRestriction(),
        ]);
    }

    /**
     * List webhooks
     */
    public function webhooks(Request $request): Response
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return redirect()->route('merchant.dashboard');
        }

        $webhooks = $this->webhookRepository->getForMerchant(
            $merchant->id,
            $request->only(['status', 'event_type', 'invoice_id']),
            $request->get('per_page', 20)
        );

        $stats = $this->webhookRepository->getStatistics($merchant->id);

        return Inertia::render('Merchant/Webhooks/Index', [
            'webhooks' => $webhooks,
            'filters' => $request->only(['status', 'event_type', 'invoice_id']),
            'stats' => $stats,
        ]);
    }

    /**
     * Settings page
     */
    public function settings(): Response
    {
        $merchant = $this->getMerchant();

        // Make webhook_secret visible for the settings page
        $merchant->makeVisible('webhook_secret');

        return Inertia::render('Merchant/Settings/Index', [
            'merchant' => $merchant,
        ]);
    }

    /**
     * API keys page
     */
    public function apiKeys(): Response
    {
        $merchant = $this->getMerchant();

        $apiKeys = $merchant->apiKeys()
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Merchant/Settings/ApiKeys', [
            'merchant' => $merchant,
            'apiKeys' => $apiKeys,
            'canOperate' => $merchant->canOperate(),
            'operationRestriction' => $merchant->getOperationRestriction(),
        ]);
    }

    /**
     * Documentation page
     */
    public function documentation(): Response
    {
        return Inertia::render('Merchant/Documentation');
    }
}
