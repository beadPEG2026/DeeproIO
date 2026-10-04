<?php

namespace App\Modules\Merchant\Services;

use App\Models\Currency\Currency;
use App\Modules\Merchant\Enums\InvoiceStatus;
use App\Modules\Merchant\Enums\PaymentStatus;
use App\Modules\Merchant\Enums\WebhookEventType;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantOrphanPayment;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Repositories\PaymentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected PaymentRepository $paymentRepository,
        protected InvoiceService $invoiceService,
        protected PricingService $pricingService,
        protected WebhookDispatcherService $webhookDispatcher
    ) {}

    /**
     * Process incoming blockchain transaction
     */
    public function processIncomingTransaction(array $txData): ?MerchantInvoicePayment
    {

        $toAddress = $txData['to_address'];
        $memo = $txData['memo'] ?? null;
        $currencyId = $txData['currency_id'];

        // Find the deposit address
        $depositAddress = MerchantDepositAddress::where('address', $toAddress)
            ->where('currency_id', $currencyId)
            ->where('status', MerchantDepositAddress::STATUS_ASSIGNED)
            ->first();

        if (!$depositAddress) {
            // Check if address exists but not assigned (orphan payment)
            $existingAddress = MerchantDepositAddress::where('address', $toAddress)
                ->where('currency_id', $currencyId)
                ->first();

            if ($existingAddress) {
                return $this->handleOrphanPayment($txData, $existingAddress);
            }

            Log::warning("Payment to unknown address", ['address' => $toAddress]);
            return null;
        }

        // Get the invoice
        $invoice = $depositAddress->invoice;

        if (!$invoice) {
            return $this->handleOrphanPayment($txData, $depositAddress);
        }

        // Check for duplicate transaction
        $existingPayment = $this->paymentRepository->findByTxnHash(
            $txData['txn_hash'],
            $toAddress
        );

        if ($existingPayment) {
            Log::debug("Duplicate transaction ignored", ['txn_hash' => $txData['txn_hash']]);
            return $existingPayment;
        }

        // Check if invoice can accept payment
        if (!$this->canInvoiceAcceptPayment($invoice)) {
            return $this->handleLatePayment($txData, $invoice, $depositAddress);
        }

        return $this->createPayment($invoice, $txData, $depositAddress);
    }

    /**
     * Create a payment record
     */
    protected function createPayment(
        MerchantInvoice $invoice,
        array $txData,
        MerchantDepositAddress $depositAddress
    ): MerchantInvoicePayment {
        return DB::transaction(function () use ($invoice, $txData, $depositAddress) {
            $currency = $invoice->currencyModel;

            // Calculate USD value at detection
            $currentRate = $this->pricingService->getCurrentRate($currency);
            $amountUsd = $this->pricingService->calculateUsdAmount(
                $txData['amount'],
                $currentRate['rate_usd'] ?? $invoice->rate_usd
            );

            // Get required confirmations from currency settings
            $requiredConfirmations = $currency->merchant_confirmations ?? 3;

            // Create payment record
            $payment = MerchantInvoicePayment::create([
                'id' => Str::uuid()->toString(),
                'invoice_id' => $invoice->id,
                'merchant_id' => $invoice->merchant_id,
                'currency_id' => $invoice->currency_id,
                'deposit_address_id' => $depositAddress->id,
                'txn_hash' => $txData['txn_hash'],
                'block_number' => $txData['block_number'] ?? null,
                'block_index' => $txData['block_index'] ?? null,
                'from_address' => $txData['from_address'] ?? null,
                'to_address' => $txData['to_address'],
                'memo' => $txData['memo'] ?? null,
                'amount_crypto' => $txData['amount'],
                'amount_usd' => $amountUsd,
                'rate_usd_at_detection' => $currentRate['rate_usd'] ?? $invoice->rate_usd,
                'confirmations' => $txData['confirmations'] ?? 0,
                'required_confirmations' => $requiredConfirmations,
                'status' => PaymentStatus::DETECTING->value,
                'detected_at' => now(),
                'detection_source' => $txData['source'] ?? 'mempool',
                'detection_node' => $txData['node'] ?? null,
                'raw_transaction' => $txData['raw'] ?? null,
                'explorer_url' => $this->buildExplorerUrl($currency, $txData['txn_hash']),
            ]);

            // Update invoice with received amount
            $this->updateInvoiceReceivedAmount($invoice);

            // Record payment on address
            $depositAddress->recordPayment();

            // Notify invoice service of new payment
            $this->invoiceService->processPaymentDetected($invoice, [
                'payment_id' => $payment->id,
                'txn_hash' => $payment->txn_hash,
                'amount_crypto' => $payment->amount_crypto,
                'amount_usd' => $payment->amount_usd,
            ]);

            Log::info("Payment detected", [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'txn_hash' => $payment->txn_hash,
                'amount' => $payment->amount_crypto,
            ]);

            return $payment;
        });
    }

    /**
     * Update payment confirmations
     */
    public function updateConfirmations(MerchantInvoicePayment $payment, int $confirmations): void
    {

        $previousConfirmations = $payment->confirmations;
        $payment->updateConfirmations($confirmations);

        // If just reached first confirmation
        if ($previousConfirmations === 0 && $confirmations >= 1) {
            $payment->update([
                'status' => PaymentStatus::CONFIRMING->value,
                'first_confirmation_at' => now(),
            ]);
        }

        // Notify invoice service of confirmation progress
        $invoice = $payment->invoice;
        $this->invoiceService->processPaymentConfirming(
            $invoice,
            $confirmations,
            $payment->required_confirmations
        );

        // Check if payment is now fully confirmed
        if ($payment->hasEnoughConfirmations() && $payment->status === PaymentStatus::CONFIRMED->value) {
            $this->confirmPayment($payment);
        }
    }

    /**
     * Confirm a payment
     */
    public function confirmPayment(MerchantInvoicePayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment->update([
                'status' => PaymentStatus::CONFIRMED->value,
                'confirmed_at' => now(),
            ]);

            $invoice = $payment->invoice;

            // Update invoice received amount
            $this->updateInvoiceReceivedAmount($invoice);

            // Check if invoice is fully paid
            if ($this->isInvoiceFullyPaid($invoice)) {
                $this->invoiceService->completePayment($invoice);
            }

            Log::info("Payment confirmed", [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'confirmations' => $payment->confirmations,
            ]);
        });
    }

    /**
     * Handle payment to expired invoice
     */
    protected function handleLatePayment(
        array $txData,
        MerchantInvoice $invoice,
        MerchantDepositAddress $depositAddress
    ): ?MerchantInvoicePayment {
        // Check if within grace period
        $gracePeriod = config('merchant_acquiring.invoice.grace_period', 300);
        $expiredAt = $invoice->expired_at ?? $invoice->payment_expires_at;

        if ($expiredAt && $expiredAt->addSeconds($gracePeriod)->isFuture()) {
            // Within grace period - accept as regular payment
            Log::info("Late payment within grace period", [
                'invoice_id' => $invoice->id,
                'txn_hash' => $txData['txn_hash'],
            ]);

            // Reactivate invoice for this payment
            $invoice->update([
                'status' => InvoiceStatus::DETECTING->value,
                'expired_at' => null,
            ]);

            $payment = $this->createPayment($invoice, $txData, $depositAddress);
            $payment->update(['is_late_payment' => true]);

            return $payment;
        }

        // Outside grace period - create orphan payment
        Log::warning("Late payment outside grace period", [
            'invoice_id' => $invoice->id,
            'txn_hash' => $txData['txn_hash'],
        ]);

        $this->createOrphanPayment($txData, $depositAddress, MerchantOrphanPayment::REASON_INVOICE_EXPIRED, $invoice);

        // Notify merchant of late payment
        $this->webhookDispatcher->dispatch($invoice, WebhookEventType::INVOICE_LATE_PAYMENT, [
            'late_payment' => [
                'txn_hash' => $txData['txn_hash'],
                'amount_crypto' => $txData['amount'],
                'detected_at' => now()->toIso8601String(),
            ],
        ]);

        return null;
    }

    /**
     * Handle orphan payment
     */
    protected function handleOrphanPayment(
        array $txData,
        MerchantDepositAddress $depositAddress
    ): ?MerchantInvoicePayment {
        $reason = $depositAddress->invoice_id
            ? MerchantOrphanPayment::REASON_INVOICE_EXPIRED
            : MerchantOrphanPayment::REASON_ADDRESS_NOT_ASSIGNED;

        $relatedInvoice = $depositAddress->invoice;
        $this->createOrphanPayment($txData, $depositAddress, $reason, $relatedInvoice);

        return null;
    }

    /**
     * Create orphan payment record
     */
    protected function createOrphanPayment(
        array $txData,
        MerchantDepositAddress $depositAddress,
        string $reason,
        ?MerchantInvoice $relatedInvoice = null
    ): MerchantOrphanPayment {
        $currency = Currency::find($depositAddress->currency_id);

        // Get current rate for USD calculation
        $rate = $currency ? $this->pricingService->getCurrentRate($currency) : null;
        $amountUsd = $rate
            ? $this->pricingService->calculateUsdAmount($txData['amount'], $rate['rate_usd'])
            : null;

        $orphan = MerchantOrphanPayment::create([
            'id' => Str::uuid()->toString(),
            'merchant_id' => $depositAddress->merchant_id,
            'address_id' => $depositAddress->id,
            'currency_id' => $depositAddress->currency_id,
            'txn_hash' => $txData['txn_hash'],
            'block_number' => $txData['block_number'] ?? null,
            'from_address' => $txData['from_address'] ?? null,
            'to_address' => $txData['to_address'],
            'memo' => $txData['memo'] ?? null,
            'amount_crypto' => $txData['amount'],
            'amount_usd' => $amountUsd,
            'rate_usd_at_detection' => $rate['rate_usd'] ?? null,
            'orphan_reason' => $reason,
            'related_invoice_id' => $relatedInvoice?->id,
            'status' => MerchantOrphanPayment::STATUS_PENDING,
            'detected_at' => now(),
            'confirmations' => $txData['confirmations'] ?? 0,
            'explorer_url' => $currency ? $this->buildExplorerUrl($currency, $txData['txn_hash']) : null,
        ]);

        Log::warning("Orphan payment created", [
            'orphan_id' => $orphan->id,
            'reason' => $reason,
            'amount' => $txData['amount'],
            'txn_hash' => $txData['txn_hash'],
        ]);

        return $orphan;
    }

    /**
     * Update invoice received amounts atomically
     * MUST be called within a DB::transaction for atomicity
     */
    protected function updateInvoiceReceivedAmount(MerchantInvoice $invoice): void
    {
        // Lock and refresh the invoice to prevent race conditions
        $lockedInvoice = MerchantInvoice::where('id', $invoice->id)->lockForUpdate()->first();
        
        if (!$lockedInvoice) {
            Log::error('Invoice not found for amount update', ['invoice_id' => $invoice->id]);
            return;
        }

        // Sum all confirmed and confirming payments using precision math
        $payments = $lockedInvoice->payments()
            ->whereIn('status', [
                PaymentStatus::DETECTING->value,
                PaymentStatus::CONFIRMING->value,
                PaymentStatus::CONFIRMED->value,
            ])
            ->where('counted_in_total', true)
            ->lockForUpdate()
            ->get();

        $totalCrypto = '0';
        $totalUsd = '0';
        
        foreach ($payments as $payment) {
            $totalCrypto = math_sum($totalCrypto, (string) ($payment->amount_crypto ?? '0'));
            $totalUsd = math_sum($totalUsd, (string) ($payment->amount_usd ?? '0'));
        }

        $lockedInvoice->update([
            'amount_received_crypto' => $totalCrypto,
            'amount_received_usd' => $totalUsd,
        ]);
    }

    /**
     * Check if invoice can accept more payments
     */
    protected function canInvoiceAcceptPayment(MerchantInvoice $invoice): bool
    {
        // Check status
        $acceptableStatuses = [
            InvoiceStatus::AWAITING_PAYMENT->value,
            InvoiceStatus::DETECTING->value,
            InvoiceStatus::CONFIRMING->value,
            InvoiceStatus::UNDERPAID->value,
        ];

        if (!in_array($invoice->status, $acceptableStatuses)) {
            return false;
        }

        // Check expiration
        if ($invoice->payment_expires_at && $invoice->payment_expires_at->isPast()) {
            // Check grace period
            $gracePeriod = config('merchant_acquiring.invoice.grace_period', 300);
            if ($invoice->payment_expires_at->addSeconds($gracePeriod)->isPast()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if invoice is fully paid using precision math
     */
    protected function isInvoiceFullyPaid(MerchantInvoice $invoice): bool
    {
        $received = (string) ($invoice->amount_received_crypto ?? '0');
        $minRequired = (string) ($invoice->amount_crypto_min ?? '0');

        // All payments must be confirmed
        $unconfirmedPayments = $invoice->payments()
            ->whereIn('status', [
                PaymentStatus::DETECTING->value,
                PaymentStatus::CONFIRMING->value,
            ])
            ->count();

        if ($unconfirmedPayments > 0) {
            return false;
        }

        return math_compare($received, $minRequired) >= 0;
    }

    /**
     * Build blockchain explorer URL
     */
    protected function buildExplorerUrl(Currency $currency, string $txnHash): string
    {
        $symbol = strtolower($currency->symbol ?? '');
        $type = strtolower($currency->type ?? '');

        $explorers = [
            'btc' => "https://blockstream.info/tx/{$txnHash}",
            'eth' => "https://etherscan.io/tx/{$txnHash}",
            'erc20' => "https://etherscan.io/tx/{$txnHash}",
            'trx' => "https://tronscan.org/#/transaction/{$txnHash}",
            'trc20' => "https://tronscan.org/#/transaction/{$txnHash}",
            'bnb' => "https://bscscan.com/tx/{$txnHash}",
            'bep20' => "https://bscscan.com/tx/{$txnHash}",
            'matic' => "https://polygonscan.com/tx/{$txnHash}",
            'matic20' => "https://polygonscan.com/tx/{$txnHash}",
            'sol' => "https://solscan.io/tx/{$txnHash}",
            'solspl' => "https://solscan.io/tx/{$txnHash}",
            'xrp' => "https://xrpscan.com/ledger/{$txnHash}",
            'ton' => "https://tonscan.org/tx/{$txnHash}",
        ];

        // Try symbol first, then type
        return $explorers[$symbol] ?? $explorers[$type] ?? "https://blockchair.com/search?q={$txnHash}";
    }

    /**
     * Get payments needing confirmation check
     */
    public function getPaymentsNeedingConfirmationCheck(): \Illuminate\Database\Eloquent\Collection
    {
        return MerchantInvoicePayment::whereIn('status', [
            PaymentStatus::DETECTING->value,
            PaymentStatus::CONFIRMING->value,
        ])
            ->orderBy('detected_at', 'asc')
            ->get();
    }

    /**
     * Mark payment as failed
     */
    public function markPaymentFailed(MerchantInvoicePayment $payment, string $reason): void
    {
        $payment->update([
            'status' => PaymentStatus::FAILED->value,
            'status_reason' => $reason,
            'failed_at' => now(),
            'counted_in_total' => false,
        ]);

        // Update invoice received amount
        $this->updateInvoiceReceivedAmount($payment->invoice);

        Log::error("Payment marked as failed", [
            'payment_id' => $payment->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Classify payment type using precision math
     */
    public function classifyPayment(MerchantInvoicePayment $payment): string
    {
        $invoice = $payment->invoice;
        $totalReceived = (string) ($invoice->amount_received_crypto ?? '0');
        $expected = (string) ($invoice->amount_crypto ?? '0');
        $tolerance = (string) config('merchant_acquiring.payment.exact_tolerance_percent', 1.0);

        // Avoid division by zero
        if (math_compare($expected, '0') == 0) {
            return MerchantInvoicePayment::CLASSIFICATION_EXACT;
        }

        // Calculate variance: ((received - expected) / expected) * 100
        $difference = math_sub($totalReceived, $expected);
        $variance = math_multiply(math_divide($difference, $expected), '100');

        // Check absolute variance
        $absVariance = ltrim($variance, '-');
        if (math_compare($absVariance, $tolerance) <= 0) {
            return MerchantInvoicePayment::CLASSIFICATION_EXACT;
        }

        // Check if underpaid
        if (math_compare($variance, '0') < 0) {
            return MerchantInvoicePayment::CLASSIFICATION_PARTIAL;
        }

        return MerchantInvoicePayment::CLASSIFICATION_OVERPAYMENT;
    }
}
