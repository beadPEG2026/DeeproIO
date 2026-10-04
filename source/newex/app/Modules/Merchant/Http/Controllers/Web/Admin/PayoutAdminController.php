<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantPayout;
use App\Modules\Merchant\Services\FundCollectionService;
use App\Modules\Merchant\Services\PayoutService;
use App\Modules\Merchant\Services\PayoutTransferService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutAdminController extends Controller
{
    public function __construct(
        protected PayoutService $payoutService,
        protected PayoutTransferService $payoutTransferService,
        protected FundCollectionService $fundCollectionService
    ) {}

    /**
     * Earnings overview for admin
     */
    public function earningsOverview(Request $request): Response
    {
        // Overall platform statistics
        $stats = [
            'total_volume' => Merchant::sum('total_volume_usd'),
            'total_fees_collected' => Merchant::sum('total_fees_usd'),
            'total_merchant_earnings' => Merchant::selectRaw('SUM(total_volume_usd - total_fees_usd) as total')->value('total') ?? 0,
            'total_paid_out' => Merchant::sum('total_paid_out_usd'),
            'total_pending_payouts' => MerchantPayout::pending()->sum('amount_usd'),
            'active_merchants' => Merchant::where('status', 'active')->count(),
            'total_invoices' => Merchant::sum('total_invoices'),
            'total_paid_invoices' => Merchant::sum('paid_invoices'),
        ];

        // Today's stats
        $today = now()->toDateString();
        $stats['today_volume'] = MerchantInvoice::whereDate('paid_at', $today)
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->sum('amount_usd');
        $stats['today_fees'] = MerchantInvoice::whereDate('paid_at', $today)
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->sum('fee_amount_usd');

        // Monthly earnings chart
        $monthlyData = $this->getMonthlyPlatformEarnings();

        // Top merchants by volume
        $topMerchants = Merchant::where('status', 'active')
            ->orderBy('total_volume_usd', 'desc')
            ->limit(10)
            ->get(['id', 'business_name', 'total_volume_usd', 'total_fees_usd', 'paid_invoices']);

        return Inertia::render('Admin/Merchant/Earnings/Index', [
            'stats' => $stats,
            'monthlyData' => $monthlyData,
            'topMerchants' => $topMerchants,
        ]);
    }

    /**
     * All deposits across merchants
     */
    public function deposits(Request $request): Response
    {
        $query = MerchantInvoicePayment::with(['merchant', 'invoice.currencyModel', 'currency', 'depositAddress.network']);

        // Apply filters
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('detected_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('detected_at', '<=', $request->date_to);
        }

        $deposits = $query->orderBy('detected_at', 'desc')->paginate(30);

        // Stats
        $depositStats = [
            'total_deposits' => MerchantInvoicePayment::count(),
            'confirmed_deposits' => MerchantInvoicePayment::where('status', 'confirmed')->count(),
            'total_volume_usd' => MerchantInvoicePayment::where('status', 'confirmed')->sum('amount_usd'),
            'pending_deposits' => MerchantInvoicePayment::whereIn('status', ['detecting', 'confirming'])->count(),
        ];

        // Merchants for filter dropdown
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);

        return Inertia::render('Admin/Merchant/Earnings/Deposits', [
            'deposits' => $deposits,
            'stats' => $depositStats,
            'merchants' => $merchants,
            'filters' => $request->only(['merchant_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Retry sweep for a deposit address
     */
    public function retrySweep(Request $request, string $depositAddressId)
    {
        $depositAddress = MerchantDepositAddress::with(['network', 'invoice.currencyModel'])
            ->findOrFail($depositAddressId);

        // Check if sweep can be retried (failed state: is_sweep_required = false AND swept_at = null)
        if ($depositAddress->is_sweep_required || $depositAddress->swept_at !== null) {
            return redirect()->back()->with('error', 'This deposit address is not eligible for sweep retry');
        }

        try {
            // Mark for sweep again
            $depositAddress->is_sweep_required = true;
            $depositAddress->save();

            // Get network slug to determine collection method
            $networkSlug = strtolower($depositAddress->network->slug ?? '');
            $currency = $depositAddress->invoice->currencyModel ?? null;

            if (!$currency) {
                $depositAddress->is_sweep_required = false;
                $depositAddress->save();
                return redirect()->back()->with('error', 'Currency not found for this deposit');
            }

            // Get uncollected payments amount
            $uncollectedAmount = MerchantInvoicePayment::where('deposit_address_id', $depositAddress->id)
                ->where('status', 'confirmed')
                ->whereNull('collected_at')
                ->sum('amount_crypto');

            if ($uncollectedAmount <= 0) {
                // Mark as swept since there's nothing to collect
                $depositAddress->is_sweep_required = false;
                $depositAddress->swept_at = now();
                $depositAddress->save();
                return redirect()->back()->with('success', 'No funds to sweep, marked as complete');
            }

            // Execute sweep based on network
            $result = $this->executeSweep($depositAddress, $currency, (string) $uncollectedAmount, $networkSlug);

            if ($result['success']) {
                return redirect()->back()->with('success', 'Sweep retry successful. TX: ' . ($result['txn_hash'] ?? 'pending'));
            } else {
                // Mark as failed again
                $depositAddress->is_sweep_required = false;
                $depositAddress->save();
                return redirect()->back()->with('error', 'Sweep retry failed: ' . ($result['error'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            // Revert on error
            $depositAddress->is_sweep_required = false;
            $depositAddress->save();
            return redirect()->back()->with('error', 'Error retrying sweep: ' . $e->getMessage());
        }
    }

    /**
     * Execute sweep based on network type
     */
    protected function executeSweep(MerchantDepositAddress $address, $currency, string $amount, string $networkSlug): array
    {
        if (in_array($networkSlug, ['erc', 'erc20', 'eth'])) {
            $network = $networkSlug === 'eth' ? 'eth' : 'erc';
            return $this->fundCollectionService->collectErcFunds($address, $currency, $amount, $network);
        }

        if (in_array($networkSlug, ['trc', 'trc20', 'trx'])) {
            $network = $networkSlug === 'trx' ? 'trx' : 'trc';
            return $this->fundCollectionService->collectTrcFunds($address, $currency, $amount, $network);
        }

        if (in_array($networkSlug, ['bep', 'bep20', 'bsc', 'bnb'])) {
            $network = $networkSlug === 'bnb' ? 'bnb' : 'bep';
            return $this->fundCollectionService->collectBepFunds($address, $currency, $amount, $network);
        }

        if (in_array($networkSlug, ['polygon', 'matic', 'matic20'])) {
            $network = $networkSlug === 'matic' ? 'matic' : 'matic20';
            return $this->fundCollectionService->collectPolygonFunds($address, $currency, $amount, $network);
        }

        if (in_array($networkSlug, ['sol', 'solana', 'spl'])) {
            $isSpl = in_array($networkSlug, ['spl']);
            return $this->fundCollectionService->collectSolanaFunds($address, $currency, $amount, $isSpl);
        }

        if (in_array($networkSlug, ['btc', 'bitcoin'])) {
            return $this->fundCollectionService->collectBitcoinFunds($address, $currency, $amount);
        }

        return ['success' => false, 'error' => 'Unsupported network: ' . $networkSlug];
    }

    /**
     * Payouts management page
     */
    public function payouts(Request $request)
    {
        $query = MerchantPayout::with(['merchant', 'currency', 'network', 'processedBy']);

        // Apply filters
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $payouts = $query->orderBy('created_at', 'desc')->paginate(30);

        // Stats
        $payoutStats = [
            'pending_count' => MerchantPayout::pending()->count(),
            'pending_amount' => MerchantPayout::pending()->sum('amount_usd'),
            'approved_count' => MerchantPayout::approved()->count(),
            'approved_amount' => MerchantPayout::approved()->sum('amount_usd'),
            'completed_count' => MerchantPayout::completed()->count(),
            'completed_amount' => MerchantPayout::completed()->sum('net_amount_usd'),
            'failed_count' => MerchantPayout::failed()->count(),
            'failed_amount' => MerchantPayout::failed()->sum('amount_usd'),
            'total_fees_collected' => MerchantPayout::completed()->sum('fee_usd'),
        ];

        // Merchants for filter dropdown
        $merchants = Merchant::orderBy('business_name')->get(['id', 'business_name']);

        return Inertia::render('Admin/Merchant/Earnings/Payouts', [
            'payouts' => $payouts,
            'stats' => $payoutStats,
            'merchants' => $merchants,
            'filters' => $request->only(['merchant_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Show single payout details
     */
    public function showPayout(string $id): Response
    {
        $payout = MerchantPayout::with(['merchant', 'currency', 'network', 'requestedBy', 'processedBy'])
            ->findOrFail($id);

        return Inertia::render('Admin/Merchant/Earnings/PayoutShow', [
            'payout' => $payout,
        ]);
    }

    /**
     * Approve a payout
     */
    public function approvePayout(Request $request, string $id)
    {
        $payout = MerchantPayout::findOrFail($id);

        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'send_immediately' => 'nullable|boolean',
        ]);

        try {
            $this->payoutService->approvePayout($payout, auth()->id(), $request->notes);

            // If send_immediately is true, process the payout right away
            if ($request->boolean('send_immediately')) {
                $result = $this->payoutTransferService->processAndSendPayout($payout->fresh());

                if ($result['success']) {
                    return redirect()->back()->with('success', 'Payout approved and sent successfully. TX: ' . $result['txn_hash']);
                } else {
                    return redirect()->back()->with('error', 'Payout approved but sending failed: ' . $result['error']);
                }
            }

            return redirect()->back()->with('success', 'Payout approved successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Process and send an approved payout
     */
    public function processPayout(Request $request, string $id)
    {
        $payout = MerchantPayout::findOrFail($id);

        if (!$payout->isApproved()) {
            return redirect()->back()->with('error', 'Payout must be approved before processing');
        }

        try {
            $result = $this->payoutTransferService->processAndSendPayout($payout);

            if ($result['success']) {
                return redirect()->back()->with('success', 'Payout sent successfully. TX: ' . $result['txn_hash']);
            } else {
                return redirect()->back()->with('error', 'Payout failed: ' . $result['error']);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error processing payout: ' . $e->getMessage());
        }
    }

    /**
     * Retry a failed payout
     */
    public function retryPayout(Request $request, string $id)
    {
        $payout = MerchantPayout::findOrFail($id);

        if (!$payout->isFailed()) {
            return redirect()->back()->with('error', 'Only failed payouts can be retried');
        }

        try {
            $result = $this->payoutTransferService->retryPayout($payout);

            if ($result['success']) {
                return redirect()->back()->with('success', 'Payout retry successful. TX: ' . $result['txn_hash']);
            } else {
                return redirect()->back()->with('error', 'Payout retry failed: ' . $result['error']);
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error retrying payout: ' . $e->getMessage());
        }
    }

    /**
     * Reject a payout
     */
    public function rejectPayout(Request $request, string $id)
    {
        $payout = MerchantPayout::findOrFail($id);

        $request->validate([
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->payoutService->rejectPayout($payout, auth()->id(), $request->reason, $request->notes);

            return redirect()->back()->with('success', 'Payout rejected');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Mark payout as completed (after manual transfer)
     */
    public function completePayout(Request $request, string $id)
    {
        throw \Illuminate\Validation\ValidationException::withMessages(['txn_hash' => __('Manual merchant completion requires a verified receipt adapter; use the tracked processing flow.')]);
    }

    /**
     * Merchant earnings detail
     */
    public function merchantEarnings(string $merchantId): Response
    {
        $merchant = Merchant::findOrFail($merchantId);

        // Earnings statistics
        $stats = [
            'total_volume' => $merchant->total_volume_usd,
            'total_fees' => $merchant->total_fees_usd,
            'net_earnings' => $merchant->getNetEarnings(),
            'available_balance' => $merchant->available_balance_usd,
            'pending_balance' => $merchant->pending_balance_usd,
            'total_paid_out' => $merchant->total_paid_out_usd,
            'paid_invoices' => $merchant->paid_invoices,
            'total_invoices' => $merchant->total_invoices,
        ];

        // Recent invoices
        $recentInvoices = MerchantInvoice::where('merchant_id', $merchantId)
            ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
            ->with(['currencyModel'])
            ->orderBy('paid_at', 'desc')
            ->limit(20)
            ->get();

        // Recent payouts
        $recentPayouts = MerchantPayout::where('merchant_id', $merchantId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Monthly breakdown
        $monthlyData = $this->getMerchantMonthlyEarnings($merchantId);

        return Inertia::render('Admin/Merchant/Earnings/MerchantDetail', [
            'merchant' => $merchant,
            'stats' => $stats,
            'recentInvoices' => $recentInvoices,
            'recentPayouts' => $recentPayouts,
            'monthlyData' => $monthlyData,
        ]);
    }

    /**
     * Get monthly platform earnings
     */
    protected function getMonthlyPlatformEarnings(): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();

            $data = MerchantInvoice::whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->selectRaw('COALESCE(SUM(amount_usd), 0) as volume, COALESCE(SUM(fee_amount_usd), 0) as fees')
                ->first();

            $months[] = [
                'month' => $date->format('M Y'),
                'volume' => (float) ($data->volume ?? 0),
                'fees' => (float) ($data->fees ?? 0),
            ];
        }

        return $months;
    }

    /**
     * Get monthly earnings for a specific merchant
     */
    protected function getMerchantMonthlyEarnings(string $merchantId): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();

            $data = MerchantInvoice::where('merchant_id', $merchantId)
                ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->selectRaw('COALESCE(SUM(amount_usd), 0) as volume, COALESCE(SUM(fee_amount_usd), 0) as fees, COUNT(*) as count')
                ->first();

            $months[] = [
                'month' => $date->format('M Y'),
                'volume' => (float) ($data->volume ?? 0),
                'fees' => (float) ($data->fees ?? 0),
                'earnings' => (float) (($data->volume ?? 0) - ($data->fees ?? 0)),
                'invoices' => (int) ($data->count ?? 0),
            ];
        }

        return $months;
    }
}
