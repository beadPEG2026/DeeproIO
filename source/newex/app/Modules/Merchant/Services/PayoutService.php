<?php

namespace App\Modules\Merchant\Services;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantPayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayoutService
{
    /**
     * Default payout fee percentage
     */
    protected string $defaultFeePercent = '0.5';

    protected MerchantNotificationService $notificationService;

    public function __construct(MerchantNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Create a payout request
     */
    public function createPayoutRequest(
        Merchant $merchant,
        string $amountUsd,
        string $payoutAddress,
        ?string $payoutMemo = null,
        ?int $currencyId = null,
        ?int $networkId = null,
        ?string $notes = null
    ): MerchantPayout {
        // Validate currency and network are provided
        if (!$currencyId || !$networkId) {
            throw new \InvalidArgumentException('Currency and network must be selected for payout');
        }

        // Validate currency exists
        $currency = Currency::find($currencyId);
        if (!$currency) {
            throw new \InvalidArgumentException('Invalid currency selected');
        }

        // Validate network exists
        $network = Network::find($networkId);
        if (!$network) {
            throw new \InvalidArgumentException('Invalid network selected');
        }

        // Validate payout address format
        if (!$this->validatePayoutAddress($payoutAddress, $network)) {
            throw new \InvalidArgumentException('Invalid payout address format for the selected network');
        }

        return DB::transaction(function () use ($merchant, $amountUsd, $payoutAddress, $payoutMemo, $currencyId, $networkId, $notes) {
            // Lock merchant row to prevent race conditions
            $lockedMerchant = Merchant::where('id', $merchant->id)->lockForUpdate()->first();
            
            if (!$lockedMerchant) {
                throw new \InvalidArgumentException('Merchant not found');
            }

            // Validate amount with locked data
            if (!$lockedMerchant->canRequestPayout($amountUsd)) {
                throw new \InvalidArgumentException('Invalid payout amount or insufficient balance');
            }

            // Calculate fees using precision math
            $feeUsd = $this->calculatePayoutFee($lockedMerchant, $amountUsd);
            $netAmountUsd = math_sub($amountUsd, $feeUsd);

            // Reserve the balance
            $lockedMerchant->reserveForPayout($amountUsd);

            // Create payout record
            $payout = MerchantPayout::create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $lockedMerchant->id,
                'amount_usd' => $amountUsd,
                'fee_usd' => $feeUsd,
                'net_amount_usd' => $netAmountUsd,
                'currency_id' => $currencyId,
                'network_id' => $networkId,
                'payout_address' => $payoutAddress,
                'payout_memo' => $payoutMemo,
                'status' => MerchantPayout::STATUS_PENDING,
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'merchant_notes' => $notes,
            ]);

            Log::info('Merchant payout request created', [
                'payout_id' => $payout->id,
                'merchant_id' => $lockedMerchant->id,
                'amount_usd' => $amountUsd,
                'net_amount_usd' => $netAmountUsd,
                'currency_id' => $currencyId,
                'network_id' => $networkId,
            ]);

            // Notify admin about new payout request
            $this->notificationService->notifyPayoutRequested($payout);

            return $payout;
        }, 5); // 5 retries on deadlock
    }

    /**
     * Validate payout address format for the network
     */
    protected function validatePayoutAddress(string $address, Network $network): bool
    {
        $networkSlug = strtolower($network->slug);
        
        // Basic validation patterns per network type
        $patterns = [
            'eth' => '/^0x[a-fA-F0-9]{40}$/',
            'erc20' => '/^0x[a-fA-F0-9]{40}$/',
            'erc' => '/^0x[a-fA-F0-9]{40}$/',
            'bnb' => '/^0x[a-fA-F0-9]{40}$/',
            'bep20' => '/^0x[a-fA-F0-9]{40}$/',
            'bep' => '/^0x[a-fA-F0-9]{40}$/',
            'bsc' => '/^0x[a-fA-F0-9]{40}$/',
            'polygon' => '/^0x[a-fA-F0-9]{40}$/',
            'matic' => '/^0x[a-fA-F0-9]{40}$/',
            'matic20' => '/^0x[a-fA-F0-9]{40}$/',
            'trx' => '/^T[a-zA-Z0-9]{33}$/',
            'trc20' => '/^T[a-zA-Z0-9]{33}$/',
            'trc' => '/^T[a-zA-Z0-9]{33}$/',
            'sol' => '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            'solana' => '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            'spl' => '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            'btc' => '/^(1|3|bc1)[a-zA-HJ-NP-Z0-9]{25,62}$/',
            'xrp' => '/^r[0-9a-zA-Z]{24,34}$/',
            'ripple' => '/^r[0-9a-zA-Z]{24,34}$/',
            'ton' => '/^(EQ|UQ)[a-zA-Z0-9_-]{46}$/',
        ];

        // If we have a pattern for this network, validate
        if (isset($patterns[$networkSlug])) {
            return preg_match($patterns[$networkSlug], $address) === 1;
        }

        // For unknown networks, ensure address is not empty and reasonable length
        return strlen($address) >= 26 && strlen($address) <= 128;
    }

    /**
     * Calculate payout fee using precision math
     */
    public function calculatePayoutFee(Merchant $merchant, string $amountUsd): string
    {
        // Use merchant-specific fee or default
        $feePercent = $this->defaultFeePercent;

        return math_percentage($amountUsd, $feePercent);
    }

    /**
     * Approve a payout request
     */
    public function approvePayout(MerchantPayout $payout, int $adminId, ?string $notes = null): void
    {
        if (!$payout->isPending()) {
            throw new \InvalidArgumentException('Payout is not in pending status');
        }

        $payout->approve($adminId, $notes);

        Log::info('Merchant payout approved', [
            'payout_id' => $payout->id,
            'admin_id' => $adminId,
        ]);

        // Notify merchant
        $this->notificationService->notifyPayoutApproved($payout);
    }

    /**
     * Reject a payout request
     */
    public function rejectPayout(MerchantPayout $payout, int $adminId, string $reason, ?string $notes = null): void
    {
        if (!$payout->isPending()) {
            throw new \InvalidArgumentException('Payout is not in pending status');
        }

        $payout->reject($adminId, $reason, $notes);

        Log::info('Merchant payout rejected', [
            'payout_id' => $payout->id,
            'admin_id' => $adminId,
            'reason' => $reason,
        ]);

        // Notify merchant
        $this->notificationService->notifyPayoutRejected($payout, $reason);
    }

    /**
     * Process an approved payout (send funds)
     */
    public function processPayout(MerchantPayout $payout): void
    {
        if (!$payout->isApproved()) {
            throw new \InvalidArgumentException('Payout is not approved');
        }

        $payout->markProcessing();

        Log::info('Merchant payout processing started', [
            'payout_id' => $payout->id,
        ]);
    }

    /**
     * Complete a payout after funds are sent
     */
    public function completePayout(MerchantPayout $payout, string $txnHash, ?string $explorerUrl = null): void
    {
        if (!$payout->isProcessing() && !$payout->isApproved()) {
            throw new \InvalidArgumentException('Payout is not in a completable status');
        }

        $payout->complete($txnHash, $explorerUrl);

        Log::info('Merchant payout completed', [
            'payout_id' => $payout->id,
            'txn_hash' => $txnHash,
        ]);

        // Notify merchant
        $this->notificationService->notifyPayoutCompleted($payout);
    }

    /**
     * Cancel a payout request
     */
    public function cancelPayout(MerchantPayout $payout): void
    {
        $payout->cancel();

        Log::info('Merchant payout cancelled', [
            'payout_id' => $payout->id,
        ]);
    }

    /**
     * Get payout statistics for a merchant
     */
    public function getMerchantPayoutStats(string $merchantId): array
    {
        $payouts = MerchantPayout::where('merchant_id', $merchantId);

        return [
            'total_requested' => $payouts->sum('amount_usd'),
            'total_completed' => (clone $payouts)->completed()->sum('net_amount_usd'),
            'total_pending' => (clone $payouts)->pending()->sum('amount_usd'),
            'total_fees_paid' => (clone $payouts)->completed()->sum('fee_usd'),
            'payout_count' => $payouts->count(),
            'completed_count' => (clone $payouts)->completed()->count(),
            'pending_count' => (clone $payouts)->pending()->count(),
        ];
    }

    /**
     * Get all pending payouts for admin
     */
    public function getPendingPayouts(int $perPage = 20)
    {
        return MerchantPayout::with(['merchant', 'currency', 'network'])
            ->pending()
            ->orderBy('requested_at', 'asc')
            ->paginate($perPage);
    }

    /**
     * Get payout history for a merchant
     */
    public function getMerchantPayouts(string $merchantId, array $filters = [], int $perPage = 20)
    {
        $query = MerchantPayout::where('merchant_id', $merchantId)
            ->with(['currency', 'network']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Credit earnings to merchant when invoice is paid
     * MUST be called within DB::transaction with merchant locked
     */
    public function creditMerchantEarnings(Merchant $merchant, string $grossAmountUsd, string $feeUsd): void
    {
        $netAmountUsd = math_sub($grossAmountUsd, $feeUsd);

        $merchant->addEarnings($netAmountUsd);

        Log::info('Merchant earnings credited', [
            'merchant_id' => $merchant->id,
            'gross_amount' => $grossAmountUsd,
            'fee' => $feeUsd,
            'net_amount' => $netAmountUsd,
        ]);
    }
}
