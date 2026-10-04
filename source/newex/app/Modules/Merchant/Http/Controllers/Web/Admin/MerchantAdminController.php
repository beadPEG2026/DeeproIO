<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency\Currency;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantOrphanPayment;
use App\Modules\Merchant\Models\MerchantWebhook;
use App\Modules\Merchant\Models\MerchantWebhookDeadLetter;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Repositories\MerchantRepository;
use App\Modules\Merchant\Repositories\WebhookRepository;
use App\Modules\Merchant\Services\AddressManagerService;
use App\Modules\Merchant\Services\FundCollectionService;
use App\Modules\Merchant\Services\MerchantNotificationService;
use App\Modules\Merchant\Services\RefundService;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MerchantAdminController extends Controller
{
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected InvoiceRepository $invoiceRepository,
        protected WebhookRepository $webhookRepository,
        protected AddressManagerService $addressManager,
        protected MerchantNotificationService $notificationService,
        protected WebhookDispatcherService $webhookDispatcher,
        protected RefundService $refundService,
        protected FundCollectionService $fundCollectionService
    ) {}

    /**
     * Admin dashboard
     */
    public function dashboard(): Response
    {
        $pendingPayouts = \App\Modules\Merchant\Models\MerchantPayout::whereIn('status', ['pending', 'approved'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_usd), 0) as total_usd')
            ->first();

        $stats = [
            'merchants' => $this->merchantRepository->getStatistics(),
            'today_invoices' => $this->getTodayInvoiceStats(),
            'pending_webhooks' => $this->webhookRepository->getPending(0)->count(),
            'dead_letter_webhooks' => MerchantWebhookDeadLetter::unresolved()->count(),
            'orphan_payments' => MerchantOrphanPayment::unresolved()->count(),
            'address_pool' => $this->addressManager->getPoolStatus(),
            'pending_payouts' => [
                'count' => $pendingPayouts->count ?? 0,
                'total_usd' => (float) ($pendingPayouts->total_usd ?? 0),
            ],
        ];

        return Inertia::render('Admin/Merchant/Dashboard', [
            'stats' => $stats,
        ]);
    }

    /**
     * List all merchants
     */
    public function merchants(Request $request): Response
    {
        $merchants = $this->merchantRepository->getAll(
            $request->only(['status', 'verification_status', 'search', 'needs_review', 'high_risk', 'sort', 'order']),
            $request->get('per_page', 20)
        );

        return Inertia::render('Admin/Merchant/Merchants/Index', [
            'merchants' => $merchants,
            'filters' => $request->only(['status', 'verification_status', 'search']),
            'stats' => $this->merchantRepository->getStatistics(),
        ]);
    }

    /**
     * Show merchant details
     */
    public function showMerchant(string $id): Response
    {
        $merchant = Merchant::with(['apiKeys', 'assetSettings.currency'])
            ->findOrFail($id);

        $invoiceStats = $this->invoiceRepository->getStatistics($merchant->id);
        $recentInvoices = $this->invoiceRepository->getRecentInvoices($merchant->id, 10);
        $webhookStats = $this->webhookRepository->getStatistics($merchant->id);

        return Inertia::render('Admin/Merchant/Merchants/Show', [
            'merchant' => $merchant,
            'invoiceStats' => $invoiceStats,
            'recentInvoices' => $recentInvoices,
            'webhookStats' => $webhookStats,
        ]);
    }

    /**
     * Verify a merchant
     */
    public function verifyMerchant(Request $request, string $id)
    {
        $request->validate([
            'verification_status' => 'required|in:verified,rejected',
            'notes' => 'nullable|string|max:1000',
        ]);

        $merchant = Merchant::findOrFail($id);

        if ($request->verification_status === 'verified') {
            $merchant->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => auth()->id(),
                'status' => 'active',
                'rejection_reason' => null,
                'rejected_at' => null,
                'manual_review_required' => false,
                'admin_notes' => $request->notes,
            ]);

            // Send approval notification
            $this->notificationService->notifyMerchantApproved($merchant);
        } else {
            // Rejected
            $merchant->update([
                'verification_status' => 'rejected',
                'rejection_reason' => $request->notes,
                'rejected_at' => now(),
                'verified_by' => auth()->id(),
                'manual_review_required' => false,
            ]);

            // Send rejection notification
            $this->notificationService->notifyMerchantRejected($merchant, $request->notes ?? 'No reason provided');
        }

        return redirect()->back()->with('success', 'Merchant verification updated');
    }

    /**
     * Suspend a merchant
     */
    public function suspendMerchant(Request $request, string $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $merchant = Merchant::findOrFail($id);

        $merchant->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => $request->reason,
        ]);

        // Send suspension notification
        $this->notificationService->notifyMerchantSuspended($merchant, $request->reason);

        return redirect()->back()->with('success', 'Merchant suspended');
    }

    /**
     * Reactivate a suspended merchant
     */
    public function reactivateMerchant(Request $request, string $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $merchant = Merchant::findOrFail($id);

        if ($merchant->status !== 'suspended') {
            return redirect()->back()->with('error', 'Merchant is not suspended');
        }

        $merchant->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
            'admin_notes' => $request->notes ? ($merchant->admin_notes . "\n\n[Reactivated " . now()->format('Y-m-d H:i') . "]: " . $request->notes) : $merchant->admin_notes,
        ]);

        // Send reactivation notification
        $this->notificationService->notifyMerchantReactivated($merchant);

        return redirect()->back()->with('success', 'Merchant reactivated successfully');
    }

    /**
     * Update merchant limits and fee rate
     */
    public function updateLimits(Request $request, string $id)
    {
        $request->validate([
            'daily_volume_limit_usd' => 'required|numeric|min:0',
            'monthly_volume_limit_usd' => 'required|numeric|min:0',
            'single_invoice_limit_usd' => 'required|numeric|min:0',
            'fee_percent' => 'required|numeric|min:0|max:100',
        ]);

        $merchant = Merchant::findOrFail($id);

        $oldValues = [
            'daily_volume_limit_usd' => $merchant->daily_volume_limit_usd,
            'monthly_volume_limit_usd' => $merchant->monthly_volume_limit_usd,
            'single_invoice_limit_usd' => $merchant->single_invoice_limit_usd,
            'fee_percent' => $merchant->fee_percent,
        ];

        $merchant->update([
            'daily_volume_limit_usd' => $request->daily_volume_limit_usd,
            'monthly_volume_limit_usd' => $request->monthly_volume_limit_usd,
            'single_invoice_limit_usd' => $request->single_invoice_limit_usd,
            'fee_percent' => $request->fee_percent,
            'admin_notes' => $merchant->admin_notes . "\n\n[Limits updated " . now()->format('Y-m-d H:i') . " by Admin ID: " . auth()->id() . "]\n" .
                "Daily: $" . $oldValues['daily_volume_limit_usd'] . " -> $" . $request->daily_volume_limit_usd . "\n" .
                "Monthly: $" . $oldValues['monthly_volume_limit_usd'] . " -> $" . $request->monthly_volume_limit_usd . "\n" .
                "Single Invoice: $" . $oldValues['single_invoice_limit_usd'] . " -> $" . $request->single_invoice_limit_usd . "\n" .
                "Fee Rate: " . $oldValues['fee_percent'] . "% -> " . $request->fee_percent . "%",
        ]);

        return redirect()->back()->with('success', 'Merchant limits updated successfully');
    }

    /**
     * List all webhooks
     */
    public function webhooks(Request $request): Response
    {
        $query = MerchantWebhook::with(['merchant', 'invoice'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->event_type, fn($q, $type) => $q->where('event_type', $type))
            ->when($request->merchant_id, fn($q, $id) => $q->where('merchant_id', $id))
            ->orderBy('created_at', 'desc');

        $webhooks = $query->paginate(20);

        // Get statistics
        $stats = [
            'total' => MerchantWebhook::count(),
            'pending' => MerchantWebhook::pending()->count(),
            'pending_retry' => MerchantWebhook::where('status', MerchantWebhook::STATUS_PENDING_RETRY)->count(),
            'delivered' => MerchantWebhook::delivered()->count(),
            'failed' => MerchantWebhook::failed()->count(),
        ];

        return Inertia::render('Admin/Merchant/Webhooks/Index', [
            'webhooks' => $webhooks,
            'stats' => $stats,
            'filters' => $request->only(['status', 'event_type', 'merchant_id']),
            'statusOptions' => [
                ['value' => '', 'label' => 'All Statuses'],
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'processing', 'label' => 'Processing'],
                ['value' => 'delivered', 'label' => 'Delivered'],
                ['value' => 'pending_retry', 'label' => 'Pending Retry'],
                ['value' => 'failed', 'label' => 'Failed'],
            ],
        ]);
    }

    /**
     * Retry a webhook
     */
    public function retryWebhook(Request $request, string $id)
    {
        $webhook = MerchantWebhook::findOrFail($id);

        if ($webhook->status === MerchantWebhook::STATUS_DELIVERED) {
            return redirect()->back()->with('error', 'Webhook already delivered');
        }

        $success = $this->webhookDispatcher->retryWebhook($webhook);

        return redirect()->back()->with(
            $success ? 'success' : 'info',
            $success ? 'Webhook delivered successfully' : 'Webhook retry initiated'
        );
    }

    /**
     * List dead letter webhooks
     */
    public function deadLetters(Request $request): Response
    {
        $deadLetters = MerchantWebhookDeadLetter::with(['merchant', 'invoice'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Admin/Merchant/Webhooks/DeadLetters', [
            'deadLetters' => $deadLetters,
            'filters' => $request->only(['status']),
        ]);
    }

    /**
     * Resolve a dead letter webhook
     */
    public function resolveDeadLetter(Request $request, string $id)
    {
        $request->validate([
            'resolution' => 'required|in:retry,discard,manual',
            'notes' => 'nullable|string|max:1000',
        ]);

        $deadLetter = MerchantWebhookDeadLetter::findOrFail($id);

        // If retry, attempt to resend the webhook
        if ($request->resolution === 'retry') {
            $webhook = MerchantWebhook::find($deadLetter->webhook_id);
            
            if (!$webhook) {
                return redirect()->back()->with('error', 'Original webhook not found');
            }

            // Reset webhook for retry
            $webhook->update([
                'status' => MerchantWebhook::STATUS_PENDING,
                'attempt_count' => 0,
                'max_attempts' => 3,
                'next_retry_at' => null,
                'failed_at' => null,
                'circuit_breaker_active' => false,
            ]);

            // Attempt immediate delivery
            $success = $this->webhookDispatcher->retryWebhook($webhook);

            $deadLetter->resolve(
                'manual_retry',
                auth()->id(),
                $request->notes ?? ($success ? 'Retried successfully' : 'Retry dispatched')
            );

            return redirect()->back()->with(
                $success ? 'success' : 'info',
                $success ? 'Webhook retried and delivered successfully' : 'Webhook retry initiated'
            );
        }

        // Handle discard/manual resolution
        $deadLetter->update([
            'status' => $request->resolution === 'discard' ? MerchantWebhookDeadLetter::STATUS_IGNORED : MerchantWebhookDeadLetter::STATUS_RESOLVED,
            'resolution_action' => $request->resolution,
            'resolution_notes' => $request->notes,
            'resolved_at' => now(),
            'resolved_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Dead letter resolved');
    }

    /**
     * List supported assets
     */
    public function assets(): Response
    {
        $currencies = Currency::where('status', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Merchant/Assets/Index', [
            'assets' => $currencies,
            'poolStatus' => $this->addressManager->getPoolStatus(),
        ]);
    }

    /**
     * Toggle currency merchant status
     */
    public function toggleAsset(Request $request, int $id)
    {
        $currency = Currency::findOrFail($id);

        $currency->update([
            'is_merchant' => !$currency->is_merchant,
        ]);

        return redirect()->back()->with('success', 'Currency merchant status updated');
    }

    /**
     * List orphan payments
     */
    public function orphanPayments(Request $request): Response
    {
        $orphanPayments = MerchantOrphanPayment::with(['merchant', 'currency', 'relatedInvoice'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderBy('detected_at', 'desc')
            ->paginate(20);

        return Inertia::render('Admin/Merchant/OrphanPayments/Index', [
            'orphanPayments' => $orphanPayments,
            'filters' => $request->only(['status']),
            'stats' => [
                'pending' => MerchantOrphanPayment::where('status', 'pending')->count(),
                'total_pending_usd' => MerchantOrphanPayment::where('status', 'pending')->sum('amount_usd'),
            ],
        ]);
    }

    /**
     * Resolve an orphan payment
     */
    public function resolveOrphanPayment(Request $request, string $id)
    {
        $request->validate([
            'resolution' => 'required|in:credit_merchant,refund,keep',
            'notes' => 'nullable|string|max:1000',
            'refund_address' => 'required_if:resolution,refund|nullable|string|max:255',
        ]);

        $orphan = MerchantOrphanPayment::with(['merchant', 'currency', 'address'])->findOrFail($id);

        if ($orphan->status !== MerchantOrphanPayment::STATUS_PENDING) {
            return redirect()->back()->with('error', 'This orphan payment has already been resolved');
        }

        try {
            $result = match ($request->resolution) {
                'credit_merchant' => $this->resolveByCreditingMerchant($orphan, $request->notes),
                'refund' => $this->resolveByRefund($orphan, $request->refund_address, $request->notes),
                'keep' => $this->resolveByKeeping($orphan, $request->notes),
            };

            if (!$result['success']) {
                return redirect()->back()->with('error', $result['error'] ?? 'Failed to resolve orphan payment');
            }

            return redirect()->back()->with('success', $result['message'] ?? 'Orphan payment resolved successfully');
        } catch (\Exception $e) {
            Log::error('Orphan payment resolution failed', [
                'orphan_id' => $id,
                'resolution' => $request->resolution,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to resolve orphan payment: ' . $e->getMessage());
        }
    }

    /**
     * Resolve orphan payment by crediting the merchant's balance
     */
    protected function resolveByCreditingMerchant(MerchantOrphanPayment $orphan, ?string $notes): array
    {
        if (!$orphan->merchant_id) {
            return ['success' => false, 'error' => 'No merchant associated with this orphan payment'];
        }

        return DB::transaction(function () use ($orphan, $notes) {
            // Lock the orphan payment
            $lockedOrphan = MerchantOrphanPayment::where('id', $orphan->id)
                ->where('status', MerchantOrphanPayment::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrphan) {
                return ['success' => false, 'error' => 'Orphan payment status has changed'];
            }

            // Lock the merchant for balance update
            $merchant = Merchant::where('id', $lockedOrphan->merchant_id)
                ->lockForUpdate()
                ->first();

            if (!$merchant) {
                return ['success' => false, 'error' => 'Merchant not found'];
            }

            // Calculate the amount to credit (use USD amount)
            $creditAmountUsd = (string) $lockedOrphan->amount_usd;

            if (math_compare($creditAmountUsd, '0') <= 0) {
                return ['success' => false, 'error' => 'Invalid credit amount'];
            }

            // Credit the merchant's available balance
            $merchant->addEarnings($creditAmountUsd);

            // Mark orphan payment as claimed/credited
            $lockedOrphan->resolveAsCredit(
                null, // No invoice created, just balance credit
                auth()->id(),
                $notes ?? 'Balance credit approved by admin'
            );

            // Update with additional resolution details
            $lockedOrphan->update([
                'resolution_notes' => sprintf(
                    "Credited $%s USD to merchant balance. %s",
                    number_format((float) $creditAmountUsd, 2),
                    $notes ?? ''
                ),
            ]);

            Log::info('Orphan payment credited to merchant', [
                'orphan_id' => $lockedOrphan->id,
                'merchant_id' => $merchant->id,
                'amount_usd' => $creditAmountUsd,
                'amount_crypto' => $lockedOrphan->amount_crypto,
                'resolved_by' => auth()->id(),
            ]);

            return [
                'success' => true,
                'message' => sprintf(
                    'Successfully credited $%s USD to merchant %s',
                    number_format((float) $creditAmountUsd, 2),
                    $merchant->business_name
                ),
            ];
        }, 5);
    }

    /**
     * Resolve orphan payment by initiating a refund to the sender
     */
    protected function resolveByRefund(MerchantOrphanPayment $orphan, ?string $refundAddress, ?string $notes): array
    {
        // Use provided address or fall back to the original sender's address
        $destinationAddress = $refundAddress ?: $orphan->from_address;

        if (empty($destinationAddress)) {
            return ['success' => false, 'error' => 'No refund address provided and original sender address is not available'];
        }

        try {
            // Use the existing RefundService to initiate the refund
            $refund = $this->refundService->initiateOrphanPaymentRefund(
                $orphan,
                $destinationAddress,
                auth()->id()
            );

            Log::info('Orphan payment refund initiated', [
                'orphan_id' => $orphan->id,
                'refund_id' => $refund->id,
                'destination' => $destinationAddress,
                'amount_crypto' => $orphan->amount_crypto,
                'resolved_by' => auth()->id(),
            ]);

            return [
                'success' => true,
                'message' => sprintf(
                    'Refund initiated for %s %s to %s. Refund ID: %s',
                    $orphan->amount_crypto,
                    $orphan->currency->symbol ?? 'UNKNOWN',
                    substr($destinationAddress, 0, 10) . '...' . substr($destinationAddress, -6),
                    $refund->id
                ),
                'refund_id' => $refund->id,
            ];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve orphan payment by keeping/sweeping funds to platform wallet
     */
    protected function resolveByKeeping(MerchantOrphanPayment $orphan, ?string $notes): array
    {
        return DB::transaction(function () use ($orphan, $notes) {
            // Lock the orphan payment
            $lockedOrphan = MerchantOrphanPayment::where('id', $orphan->id)
                ->where('status', MerchantOrphanPayment::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrphan) {
                return ['success' => false, 'error' => 'Orphan payment status has changed'];
            }

            // Get the deposit address for fund collection
            $address = $lockedOrphan->address;
            
            if ($address && $address->private_key) {
                // Attempt to sweep funds to hot wallet
                $currency = $lockedOrphan->currency;
                $network = $address->network;

                if ($currency && $network) {
                    $sweepResult = $this->sweepOrphanFunds($address, $currency, $network, (string) $lockedOrphan->amount_crypto);
                    
                    if ($sweepResult['success']) {
                        $txnHash = $sweepResult['txn_hash'] ?? 'sweep-' . $lockedOrphan->id . '-' . time();
                        $lockedOrphan->resolveAsSweep($txnHash, auth()->id(), $notes ?? 'Funds swept to platform wallet');

                        Log::info('Orphan payment swept to hot wallet', [
                            'orphan_id' => $lockedOrphan->id,
                            'txn_hash' => $txnHash,
                            'amount_crypto' => $lockedOrphan->amount_crypto,
                            'resolved_by' => auth()->id(),
                        ]);

                        return [
                            'success' => true,
                            'message' => sprintf(
                                'Funds swept to platform wallet. Transaction: %s',
                                substr($txnHash, 0, 20) . '...'
                            ),
                        ];
                    } else {
                        Log::warning('Orphan payment sweep failed, marking as kept without sweep', [
                            'orphan_id' => $lockedOrphan->id,
                            'error' => $sweepResult['error'] ?? 'Unknown error',
                        ]);
                    }
                }
            }

            // If sweep is not possible or failed, just mark as kept
            $lockedOrphan->update([
                'status' => MerchantOrphanPayment::STATUS_SWEPT,
                'resolution_type' => MerchantOrphanPayment::RESOLUTION_SWEEP,
                'resolution_notes' => $notes ?? 'Marked as kept by admin (manual sweep may be required)',
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
            ]);

            Log::info('Orphan payment marked as kept', [
                'orphan_id' => $lockedOrphan->id,
                'amount_usd' => $lockedOrphan->amount_usd,
                'resolved_by' => auth()->id(),
            ]);

            return [
                'success' => true,
                'message' => 'Orphan payment marked as kept. Manual fund collection may be required.',
            ];
        }, 5);
    }

    /**
     * Attempt to sweep orphan funds to platform hot wallet
     */
    protected function sweepOrphanFunds($address, $currency, $network, string $amount): array
    {
        $networkSlug = strtolower($network->slug);

        try {
            if (in_array($networkSlug, ['erc', 'erc20', 'eth'])) {
                return $this->fundCollectionService->collectErcFunds($address, $currency, $amount, $networkSlug);
            } elseif (in_array($networkSlug, ['trc', 'trc20', 'trx'])) {
                return $this->fundCollectionService->collectTrcFunds($address, $currency, $amount, $networkSlug);
            } elseif (in_array($networkSlug, ['bep', 'bep20', 'bsc', 'bnb'])) {
                return $this->fundCollectionService->collectBepFunds($address, $currency, $amount, $networkSlug);
            } elseif (in_array($networkSlug, ['polygon', 'matic', 'matic20'])) {
                return $this->fundCollectionService->collectPolygonFunds($address, $currency, $amount, $networkSlug);
            } elseif (in_array($networkSlug, ['sol', 'solana', 'spl'])) {
                $isSpl = !empty($currency->sol_contract) && strtolower($currency->symbol) !== 'sol';
                return $this->fundCollectionService->collectSolanaFunds($address, $currency, $amount, $isSpl);
            } elseif (in_array($networkSlug, ['btc', 'bitcoin'])) {
                return $this->fundCollectionService->collectBitcoinFunds($address, $currency, $amount);
            }

            return ['success' => false, 'error' => 'Unsupported network for automatic sweep: ' . $network->name];
        } catch (\Exception $e) {
            Log::error('Orphan fund sweep exception', [
                'network' => $networkSlug,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reports page
     */
    public function reports(Request $request): Response
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());
        $reportType = $request->get('report_type', 'overview');

        // Parse dates
        $startDate = \Carbon\Carbon::parse($dateFrom)->startOfDay();
        $endDate = \Carbon\Carbon::parse($dateTo)->endOfDay();

        // Get comprehensive report data
        $reportData = $this->generateReportData($startDate, $endDate);

        return Inertia::render('Admin/Merchant/Reports/Index', [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'report_type' => $reportType,
            ],
            'summary' => $reportData['summary'],
            'dailyData' => $reportData['dailyData'],
            'invoicesByStatus' => $reportData['invoicesByStatus'],
            'volumeByCurrency' => $reportData['volumeByCurrency'],
            'volumeByNetwork' => $reportData['volumeByNetwork'],
            'topMerchants' => $reportData['topMerchants'],
            'merchantGrowth' => $reportData['merchantGrowth'],
            'conversionFunnel' => $reportData['conversionFunnel'],
            'hourlyDistribution' => $reportData['hourlyDistribution'],
            'payoutStats' => $reportData['payoutStats'],
            'feeAnalysis' => $reportData['feeAnalysis'],
            'recentActivity' => $reportData['recentActivity'],
        ]);
    }

    /**
     * Generate comprehensive report data
     */
    protected function generateReportData(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): array
    {
        $invoiceModel = \App\Modules\Merchant\Models\MerchantInvoice::class;
        $paymentModel = \App\Modules\Merchant\Models\MerchantInvoicePayment::class;
        $merchantModel = \App\Modules\Merchant\Models\Merchant::class;
        $payoutModel = \App\Modules\Merchant\Models\MerchantPayout::class;

        // Summary Statistics
        $summary = $this->getSummaryStats($startDate, $endDate);

        // Daily data for charts
        $dailyData = $this->getDailyData($startDate, $endDate);

        // Invoice status breakdown
        $invoicesByStatus = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('status, COUNT(*) as count, SUM(amount_usd) as total_amount')
            ->groupBy('status')
            ->get()
            ->map(fn($item) => [
                'status' => $item->status,
                'count' => $item->count,
                'total_amount' => (float) $item->total_amount,
            ]);

        // Volume by currency
        $volumeByCurrency = $invoiceModel::whereBetween('merchant_invoices.created_at', [$startDate, $endDate])
            ->whereNotNull('merchant_invoices.currency_id')
            ->whereIn('merchant_invoices.status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->join('currencies', 'merchant_invoices.currency_id', '=', 'currencies.id')
            ->selectRaw('currencies.symbol, currencies.name, COUNT(*) as count, SUM(merchant_invoices.amount_received_usd) as total_usd')
            ->groupBy('currencies.symbol', 'currencies.name')
            ->orderByDesc('total_usd')
            ->limit(10)
            ->get();

        // Volume by network
        $volumeByNetwork = $invoiceModel::whereBetween('merchant_invoices.created_at', [$startDate, $endDate])
            ->whereNotNull('merchant_invoices.network_id')
            ->whereIn('merchant_invoices.status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->join('networks', 'merchant_invoices.network_id', '=', 'networks.id')
            ->selectRaw('networks.name, networks.slug, COUNT(*) as count, SUM(merchant_invoices.amount_received_usd) as total_usd')
            ->groupBy('networks.name', 'networks.slug')
            ->orderByDesc('total_usd')
            ->limit(10)
            ->get();

        // Top merchants by volume
        $topMerchants = $merchantModel::withCount(['invoices' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate]);
            }])
            ->withSum(['invoices' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate])
                    ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled']);
            }], 'amount_received_usd')
            ->withSum(['invoices' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate])
                    ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled']);
            }], 'fee_amount_usd')
            ->orderByDesc('invoices_sum_amount_received_usd')
            ->limit(10)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'business_name' => $m->business_name,
                'invoices_count' => $m->invoices_count,
                'total_volume' => (float) ($m->invoices_sum_amount_received_usd ?? 0),
                'total_fees' => (float) ($m->invoices_sum_fee_amount_usd ?? 0),
                'status' => $m->status,
                'verified' => $m->verification_status === 'verified',
            ]);

        // Merchant growth over time
        $merchantGrowth = $merchantModel::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Conversion funnel
        $totalInvoices = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])->count();
        $currencySelected = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('currency_id')
            ->count();
        $paymentDetected = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('payments')
            ->count();
        $paidInvoices = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->count();

        $conversionFunnel = [
            ['stage' => 'Created', 'count' => $totalInvoices, 'percentage' => 100],
            ['stage' => 'Currency Selected', 'count' => $currencySelected, 'percentage' => $totalInvoices > 0 ? round($currencySelected / $totalInvoices * 100, 1) : 0],
            ['stage' => 'Payment Detected', 'count' => $paymentDetected, 'percentage' => $totalInvoices > 0 ? round($paymentDetected / $totalInvoices * 100, 1) : 0],
            ['stage' => 'Completed', 'count' => $paidInvoices, 'percentage' => $totalInvoices > 0 ? round($paidInvoices / $totalInvoices * 100, 1) : 0],
        ];

        // Hourly distribution
        $hourlyDistribution = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->selectRaw('EXTRACT(HOUR FROM paid_at) as hour, COUNT(*) as count, SUM(amount_received_usd) as volume')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour')
            ->map(fn($item) => [
                'hour' => (int) $item->hour,
                'count' => $item->count,
                'volume' => (float) $item->volume,
            ]);

        // Fill missing hours
        $fullHourlyData = [];
        for ($h = 0; $h < 24; $h++) {
            $fullHourlyData[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $hourlyDistribution->get($h)['count'] ?? 0,
                'volume' => $hourlyDistribution->get($h)['volume'] ?? 0,
            ];
        }

        // Payout statistics
        $payoutStats = [
            'total_requested' => $payoutModel::whereBetween('created_at', [$startDate, $endDate])->sum('amount_usd'),
            'total_completed' => $payoutModel::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', 'completed')
                ->sum('net_amount_usd'),
            'total_fees' => $payoutModel::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', 'completed')
                ->sum('fee_usd'),
            'pending_count' => $payoutModel::whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('status', ['pending', 'approved', 'processing'])
                ->count(),
            'completed_count' => $payoutModel::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', 'completed')
                ->count(),
            'by_status' => $payoutModel::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('status, COUNT(*) as count, SUM(amount_usd) as total')
                ->groupBy('status')
                ->get(),
        ];

        // Fee analysis
        $feeAnalysis = [
            'total_fees_collected' => $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->sum('fee_amount_usd'),
            'average_fee_rate' => $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->where('amount_received_usd', '>', 0)
                ->selectRaw('AVG(fee_amount_usd / amount_received_usd * 100) as avg_rate')
                ->value('avg_rate') ?? 0,
            'fees_by_merchant' => $invoiceModel::whereBetween('merchant_invoices.created_at', [$startDate, $endDate])
                ->whereIn('merchant_invoices.status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->join('merchants', 'merchant_invoices.merchant_id', '=', 'merchants.id')
                ->selectRaw('merchants.business_name, SUM(merchant_invoices.fee_amount_usd) as total_fees, COUNT(*) as invoice_count')
                ->groupBy('merchants.id', 'merchants.business_name')
                ->orderByDesc('total_fees')
                ->limit(10)
                ->get(),
        ];

        // Recent activity
        $recentActivity = $invoiceModel::with('merchant:id,business_name')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get()
            ->map(fn($inv) => [
                'id' => $inv->id,
                'reference' => $inv->reference,
                'merchant_name' => $inv->merchant->business_name ?? 'Unknown',
                'amount_usd' => (float) $inv->amount_usd,
                'amount_received_usd' => (float) $inv->amount_received_usd,
                'fee_amount_usd' => (float) $inv->fee_amount_usd,
                'status' => $inv->status,
                'paid_at' => $inv->paid_at?->toIso8601String(),
            ]);

        return [
            'summary' => $summary,
            'dailyData' => $dailyData,
            'invoicesByStatus' => $invoicesByStatus,
            'volumeByCurrency' => $volumeByCurrency,
            'volumeByNetwork' => $volumeByNetwork,
            'topMerchants' => $topMerchants,
            'merchantGrowth' => $merchantGrowth,
            'conversionFunnel' => $conversionFunnel,
            'hourlyDistribution' => $fullHourlyData,
            'payoutStats' => $payoutStats,
            'feeAnalysis' => $feeAnalysis,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Get summary statistics
     */
    protected function getSummaryStats(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): array
    {
        $invoiceModel = \App\Modules\Merchant\Models\MerchantInvoice::class;
        $merchantModel = \App\Modules\Merchant\Models\Merchant::class;

        // Current period stats
        $totalVolume = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->sum('amount_received_usd');

        $totalFees = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->sum('fee_amount_usd');

        $totalInvoices = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])->count();
        $paidInvoices = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->count();

        $activeMerchants = $merchantModel::whereHas('invoices', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('created_at', [$startDate, $endDate]);
        })->count();

        $newMerchants = $merchantModel::whereBetween('created_at', [$startDate, $endDate])->count();

        $avgInvoiceValue = $totalInvoices > 0 
            ? $invoiceModel::whereBetween('created_at', [$startDate, $endDate])->avg('amount_usd') 
            : 0;

        // Previous period for comparison
        $periodDays = $startDate->diffInDays($endDate);
        $prevStartDate = $startDate->copy()->subDays($periodDays + 1);
        $prevEndDate = $startDate->copy()->subDay();

        $prevVolume = $invoiceModel::whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->sum('amount_received_usd');

        $prevFees = $invoiceModel::whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->sum('fee_amount_usd');

        $prevInvoices = $invoiceModel::whereBetween('created_at', [$prevStartDate, $prevEndDate])->count();

        return [
            'total_volume' => (float) $totalVolume,
            'total_fees' => (float) $totalFees,
            'total_invoices' => $totalInvoices,
            'paid_invoices' => $paidInvoices,
            'conversion_rate' => $totalInvoices > 0 ? round($paidInvoices / $totalInvoices * 100, 1) : 0,
            'active_merchants' => $activeMerchants,
            'new_merchants' => $newMerchants,
            'avg_invoice_value' => (float) $avgInvoiceValue,
            'volume_change' => $prevVolume > 0 ? round(($totalVolume - $prevVolume) / $prevVolume * 100, 1) : 0,
            'fees_change' => $prevFees > 0 ? round(($totalFees - $prevFees) / $prevFees * 100, 1) : 0,
            'invoices_change' => $prevInvoices > 0 ? round(($totalInvoices - $prevInvoices) / $prevInvoices * 100, 1) : 0,
        ];
    }

    /**
     * Get daily data for charts
     */
    protected function getDailyData(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): array
    {
        $invoiceModel = \App\Modules\Merchant\Models\MerchantInvoice::class;

        $dailyStats = $invoiceModel::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw("DATE(created_at) as date, 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status IN ('paid', 'overpaid', 'underpaid', 'settled') THEN 1 ELSE 0 END) as paid_invoices,
                SUM(CASE WHEN status IN ('paid', 'overpaid', 'underpaid', 'settled') THEN amount_received_usd ELSE 0 END) as volume,
                SUM(CASE WHEN status IN ('paid', 'overpaid', 'underpaid', 'settled') THEN fee_amount_usd ELSE 0 END) as fees")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill in missing dates
        $result = [];
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            $dateKey = $currentDate->toDateString();
            $dayData = $dailyStats->get($dateKey);
            
            $result[] = [
                'date' => $dateKey,
                'label' => $currentDate->format('M j'),
                'total_invoices' => (int) ($dayData->total_invoices ?? 0),
                'paid_invoices' => (int) ($dayData->paid_invoices ?? 0),
                'volume' => (float) ($dayData->volume ?? 0),
                'fees' => (float) ($dayData->fees ?? 0),
            ];
            
            $currentDate->addDay();
        }

        return $result;
    }

    /**
     * Get today's invoice statistics
     */
    protected function getTodayInvoiceStats(): array
    {
        $today = now()->toDateString();

        return [
            'total' => \App\Modules\Merchant\Models\MerchantInvoice::whereDate('created_at', $today)->count(),
            'paid' => \App\Modules\Merchant\Models\MerchantInvoice::whereDate('paid_at', $today)->count(),
            'volume_usd' => \App\Modules\Merchant\Models\MerchantInvoice::whereDate('paid_at', $today)
                ->sum('amount_usd'),
        ];
    }
}
