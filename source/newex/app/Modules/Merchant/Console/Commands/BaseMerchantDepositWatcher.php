<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Models\Currency\Currency;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Services\InvoiceService;
use App\Modules\Merchant\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

abstract class BaseMerchantDepositWatcher extends Command
{
    protected PaymentService $paymentService;
    protected InvoiceService $invoiceService;

    public function __construct()
    {
        parent::__construct();
        $this->paymentService = app(PaymentService::class);
        $this->invoiceService = app(InvoiceService::class);
    }

    /**
     * Get active merchant deposit addresses for this network
     */
    protected function getActiveMerchantAddresses(int $networkId): \Illuminate\Support\Collection
    {
        return MerchantDepositAddress::query()
            ->where('network_id', $networkId)
            ->where('status', MerchantDepositAddress::STATUS_ASSIGNED)
            ->whereHas('invoice', function ($query) {
                $query->whereIn('status', [
                    MerchantInvoice::STATUS_AWAITING_PAYMENT,
                    MerchantInvoice::STATUS_DETECTING,
                    MerchantInvoice::STATUS_CONFIRMING,
                    MerchantInvoice::STATUS_UNDERPAID,
                ]);
            })
            ->with(['invoice.currencyModel', 'invoice.merchant', 'currency'])
            ->get();
    }

    /**
     * Check if payment already exists
     */
    protected function paymentExists(string $txnHash): bool
    {
        return MerchantInvoicePayment::where('txn_hash', $txnHash)->exists();
    }

    /**
     * Process a detected transaction
     */
    protected function processTransaction(
        MerchantDepositAddress $address,
        string $txnHash,
        string $amount,
        int $confirmations,
        ?string $fromAddress = null,
        ?int $blockNumber = null
    ): void {
        try {

            $invoice = $address->invoice;

            if (!$invoice) {
                Log::warning('Merchant address has no invoice', ['address_id' => $address->id]);
                return;
            }

            // Check if payment already processed
            if ($this->paymentExists($txnHash)) {

                // Update confirmations if payment exists
                $this->updatePaymentConfirmations($txnHash, $confirmations, $invoice);
                return;
            }

            Log::info('Merchant deposit detected', [
                'invoice_id' => $invoice->id,
                'address' => $address->address,
                'txn_hash' => $txnHash,
                'amount' => $amount,
                'confirmations' => $confirmations,
            ]);

            // Process the payment
            $this->paymentService->processIncomingTransaction([
                'txn_hash' => $txnHash,
                'to_address' => $address->address,
                'from_address' => $fromAddress,
                'amount' => $amount,
                'block_number' => $blockNumber,
                'confirmations' => $confirmations,
                'currency_id' => $address->currency_id,
                'network_id' => $address->network_id,
                'source' => 'merchant_watcher',
                'memo' => $address->memo,
            ]);

            // Mark address as having received payment
            $address->recordPayment();

        } catch (\Exception $e) {
            Log::error('Failed to process merchant transaction', [
                'address' => $address->address,
                'txn_hash' => $txnHash,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update confirmations for existing payment
     */
    protected function updatePaymentConfirmations(string $txnHash, int $confirmations, MerchantInvoice $invoice): void
    {
        $payment = MerchantInvoicePayment::where('txn_hash', $txnHash)->first();

        if (!$payment) {
            return;
        }

        // Get required confirmations from currency
        $requiredConfirmations = $invoice->currencyModel?->merchant_confirmations ?? 3;

        // Only update if confirmations increased
        if ($confirmations <= $payment->confirmations && $confirmations < $requiredConfirmations) {
            return;
        }

        $payment->update(['confirmations' => $confirmations]);

        // Check if payment just became confirmed (wasn't confirmed before)
        if ($confirmations >= $requiredConfirmations && $payment->status !== MerchantInvoicePayment::STATUS_CONFIRMED) {

            $payment->update([
                'status' => MerchantInvoicePayment::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);

            Log::info('Merchant payment confirmed', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'confirmations' => $confirmations,
                'required' => $requiredConfirmations,
            ]);

            // Mark deposit address for sweep (fund collection to hot wallet)
            $depositAddress = $payment->depositAddress;
            if ($depositAddress) {
                $depositAddress->markForSweep();
                Log::info('Merchant deposit address marked for sweep', [
                    'address_id' => $depositAddress->id,
                    'address' => $depositAddress->address,
                ]);
            }

            // Call PaymentService to handle confirmation completion
            app(PaymentService::class)->confirmPayment($payment);
        } elseif ($payment->status !== MerchantInvoicePayment::STATUS_CONFIRMED) {
            // Still confirming, update invoice service with progress
            $this->invoiceService->processPaymentConfirming($invoice, $confirmations, $requiredConfirmations);
        }
    }

    /**
     * Log watcher activity
     */
    protected function logActivity(string $network, int $addressCount, int $processed): void
    {
        if ($addressCount > 0) {
            Log::debug("Merchant {$network} watcher completed", [
                'addresses_checked' => $addressCount,
                'transactions_processed' => $processed,
            ]);
        }
    }
}
