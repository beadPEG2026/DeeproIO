<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantPayout;
use App\Modules\Merchant\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MerchantEarningsController extends Controller
{
    public function __construct(
        protected PayoutService $payoutService
    ) {}

    /**
     * Get the current merchant
     */
    protected function getMerchant(): ?Merchant
    {
        return Merchant::where('user_id', auth()->id())->first();
    }

    /**
     * Earnings overview page
     */
    public function earnings(Request $request): Response
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return redirect()->route('merchant.dashboard');
        }

        // Get earnings statistics
        $stats = $this->getEarningsStats($merchant);

        // Get recent transactions
        $recentTransactions = MerchantInvoice::where('merchant_id', $merchant->id)
            ->whereIn('status', [MerchantInvoice::STATUS_PAID, MerchantInvoice::STATUS_OVERPAID, MerchantInvoice::STATUS_UNDERPAID, MerchantInvoice::STATUS_SETTLED])
            ->with(['currencyModel'])
            ->orderBy('paid_at', 'desc')
            ->limit(10)
            ->get();

        // Get monthly earnings for chart
        $monthlyEarnings = $this->getMonthlyEarnings($merchant);

        return Inertia::render('Merchant/Earnings/Index', [
            'merchant' => $merchant,
            'stats' => $stats,
            'recentTransactions' => $recentTransactions,
            'monthlyEarnings' => $monthlyEarnings,
        ]);
    }

    /**
     * Deposits history page
     */
    public function deposits(Request $request): Response
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return redirect()->route('merchant.dashboard');
        }

        // Get all payments/deposits
        $query = MerchantInvoicePayment::where('merchant_id', $merchant->id)
            ->with(['invoice.currencyModel', 'currency']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('detected_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('detected_at', '<=', $request->date_to);
        }

        $deposits = $query->orderBy('detected_at', 'desc')->paginate(20);

        // Deposit statistics
        $depositStats = [
            'total_deposits' => MerchantInvoicePayment::where('merchant_id', $merchant->id)->count(),
            'confirmed_deposits' => MerchantInvoicePayment::where('merchant_id', $merchant->id)->where('status', 'confirmed')->count(),
            'total_volume_usd' => MerchantInvoicePayment::where('merchant_id', $merchant->id)->where('status', 'confirmed')->sum('amount_usd'),
            'pending_deposits' => MerchantInvoicePayment::where('merchant_id', $merchant->id)->whereIn('status', ['detecting', 'confirming'])->count(),
        ];

        return Inertia::render('Merchant/Earnings/Deposits', [
            'merchant' => $merchant,
            'deposits' => $deposits,
            'stats' => $depositStats,
            'filters' => $request->only(['status', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Payouts page
     */
    public function payouts(Request $request)
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return redirect()->route('merchant.dashboard');
        }

        // Get payouts
        $payouts = $this->payoutService->getMerchantPayouts(
            $merchant->id,
            $request->only(['status', 'date_from', 'date_to']),
            20
        );

        // Payout statistics
        $payoutStats = $this->payoutService->getMerchantPayoutStats($merchant->id);

        // Get available currencies/networks for payout
        $currencies = Currency::where('status', true)
            ->where('is_merchant', true)
            ->with('networks')
            ->get();

        return Inertia::render('Merchant/Earnings/Payouts', [
            'merchant' => $merchant,
            'payouts' => $payouts,
            'stats' => $payoutStats,
            'currencies' => $currencies,
            'filters' => $request->only(['status', 'date_from', 'date_to']),
            'canOperate' => $merchant->canOperate(),
            'operationRestriction' => $merchant->getOperationRestriction(),
        ]);
    }

    /**
     * Request a payout
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Merchant not found'],
            ], 404);
        }

        // Check if merchant can operate
        if (!$merchant->canOperate()) {
            $restriction = $merchant->getOperationRestriction();
            return response()->json([
                'success' => false,
                'error' => ['message' => $restriction['message'] ?? 'Your account is restricted from requesting payouts'],
            ], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payout_address' => 'required|string|max:255',
            'payout_memo' => 'nullable|string|max:100',
            'currency_id' => 'required|integer|exists:currencies,id',
            'network_id' => 'required|integer|exists:networks,id',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            // Validate minimum payout amount
            if ($validated['amount'] < $merchant->min_payout_amount_usd) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => "Minimum payout amount is \${$merchant->min_payout_amount_usd}"],
                ], 400);
            }

            // Validate balance
            if ($validated['amount'] > $merchant->available_balance_usd) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Insufficient available balance'],
                ], 400);
            }

            $payout = $this->payoutService->createPayoutRequest(
                $merchant,
                (string) $validated['amount'],
                $validated['payout_address'],
                $validated['payout_memo'] ?? null,
                (int) $validated['currency_id'],
                (int) $validated['network_id'],
                $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => ['payout' => $payout],
                'message' => 'Payout request submitted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['message' => $e->getMessage()],
            ], 400);
        }
    }

    /**
     * Cancel a payout request
     */
    public function cancelPayout(string $id): JsonResponse
    {
        $merchant = $this->getMerchant();

        $payout = MerchantPayout::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->first();

        if (!$payout) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Payout not found'],
            ], 404);
        }

        try {
            $this->payoutService->cancelPayout($payout);

            return response()->json([
                'success' => true,
                'message' => 'Payout cancelled successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['message' => $e->getMessage()],
            ], 400);
        }
    }

    /**
     * Update payout settings
     */
    public function updatePayoutSettings(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        $validated = $request->validate([
            'default_payout_address' => 'nullable|string|max:255',
            'default_payout_memo' => 'nullable|string|max:100',
            'auto_payout_enabled' => 'boolean',
            'auto_payout_threshold_usd' => 'nullable|numeric|min:50',
        ]);

        $merchant->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payout settings updated',
        ]);
    }

    /**
     * Get earnings statistics
     */
    protected function getEarningsStats(Merchant $merchant): array
    {
        $today = now()->toDateString();
        $thisMonth = now()->startOfMonth()->toDateString();
        $thisWeek = now()->startOfWeek()->toDateString();

        return [
            'available_balance' => $merchant->available_balance_usd,
            'pending_balance' => $merchant->pending_balance_usd,
            'total_balance' => $merchant->getTotalBalance(),
            'total_earnings' => $merchant->getNetEarnings(),
            'total_volume' => $merchant->total_volume_usd,
            'total_fees' => $merchant->total_fees_usd,
            'total_paid_out' => $merchant->total_paid_out_usd,
            'today_earnings' => MerchantInvoice::where('merchant_id', $merchant->id)
                ->whereDate('paid_at', $today)
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->selectRaw('COALESCE(SUM(amount_usd - fee_amount_usd), 0) as total')
                ->value('total') ?? 0,
            'this_week_earnings' => MerchantInvoice::where('merchant_id', $merchant->id)
                ->whereDate('paid_at', '>=', $thisWeek)
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->selectRaw('COALESCE(SUM(amount_usd - fee_amount_usd), 0) as total')
                ->value('total') ?? 0,
            'this_month_earnings' => MerchantInvoice::where('merchant_id', $merchant->id)
                ->whereDate('paid_at', '>=', $thisMonth)
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->selectRaw('COALESCE(SUM(amount_usd - fee_amount_usd), 0) as total')
                ->value('total') ?? 0,
            'paid_invoices' => $merchant->paid_invoices,
            'total_invoices' => $merchant->total_invoices,
        ];
    }

    /**
     * Get monthly earnings for chart
     */
    protected function getMonthlyEarnings(Merchant $merchant): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();

            $earnings = MerchantInvoice::where('merchant_id', $merchant->id)
                ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
                ->whereIn('status', ['paid', 'overpaid', 'underpaid', 'settled'])
                ->selectRaw('COALESCE(SUM(amount_usd - fee_amount_usd), 0) as earnings, COALESCE(SUM(amount_usd), 0) as volume')
                ->first();

            $months[] = [
                'month' => $date->format('M Y'),
                'earnings' => (float) ($earnings->earnings ?? 0),
                'volume' => (float) ($earnings->volume ?? 0),
            ];
        }

        return $months;
    }
}
