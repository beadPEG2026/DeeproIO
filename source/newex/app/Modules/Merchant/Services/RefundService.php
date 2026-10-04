<?php

namespace App\Modules\Merchant\Services;

use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Models\MerchantOrphanPayment;
use App\Modules\Merchant\Models\MerchantRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefundService
{
    protected MerchantNotificationService $notificationService;
    protected RefundTransferService $transferService;

    public function __construct(
        MerchantNotificationService $notificationService,
        RefundTransferService $transferService
    ) {
        $this->notificationService = $notificationService;
        $this->transferService = $transferService;
    }

    /**
     * Initiate a refund for an invoice
     */
    public function initiateRefund(
        MerchantInvoice $invoice,
        string $destinationAddress,
        ?string $amount = null,
        ?string $reason = null,
        ?string $reasonDetails = null,
        ?int $requestedBy = null,
        bool $autoApprove = false
    ): MerchantRefund {
        // Validate invoice can be refunded
        $this->validateRefundable($invoice);

        // Get payment and currency details
        $payment = $invoice->payments()->where('status', 'confirmed')->first();
        if (!$payment) {
            throw new \InvalidArgumentException('No confirmed payment found for this invoice');
        }

        $currency = Currency::find($invoice->currency_id);
        $network = Network::find($invoice->network_id);

        if (!$currency || !$network) {
            throw new \InvalidArgumentException('Currency or network not found');
        }

        // Determine refund type and amount
        $refundType = MerchantRefund::TYPE_FULL;
        $amountCrypto = $invoice->amount_received_crypto;
        $amountUsd = $invoice->amount_received_usd;

        if ($amount !== null && math_compare($amount, $invoice->amount_received_crypto) < 0) {
            $refundType = MerchantRefund::TYPE_PARTIAL;
            $amountCrypto = $amount;
            // Calculate USD equivalent
            $rate = $invoice->rate_usd ?? $currency->rate ?? 0;
            $amountUsd = math_multiply($amountCrypto, (string) $rate);
        }

        // Handle overpayment refunds
        if ($invoice->status === MerchantInvoice::STATUS_OVERPAID) {
            $refundType = MerchantRefund::TYPE_OVERPAYMENT;
            if ($amount === null) {
                // Default to refunding the overpayment amount
                $overpaymentCrypto = math_sub($invoice->amount_received_crypto, $invoice->amount_crypto);
                if (math_compare($overpaymentCrypto, '0') > 0) {
                    $amountCrypto = $overpaymentCrypto;
                    $rate = $invoice->rate_usd ?? $currency->rate ?? 0;
                    $amountUsd = math_multiply($amountCrypto, (string) $rate);
                }
            }
        }

        return DB::transaction(function () use (
            $invoice, $payment, $currency, $network, $destinationAddress,
            $refundType, $amountCrypto, $amountUsd, $reason, $reasonDetails,
            $requestedBy, $autoApprove
        ) {
            // Lock the invoice
            $lockedInvoice = MerchantInvoice::where('id', $invoice->id)->lockForUpdate()->first();
            
            // Lock the merchant for balance updates
            $merchant = Merchant::where('id', $lockedInvoice->merchant_id)->lockForUpdate()->first();

            // Create refund record
            $refund = MerchantRefund::create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'invoice_id' => $lockedInvoice->id,
                'payment_id' => $payment->id,
                'refund_type' => $refundType,
                'reason' => $reason ?? MerchantRefund::REASON_MERCHANT_REQUEST,
                'reason_details' => $reasonDetails,
                'amount_crypto' => $amountCrypto,
                'amount_usd' => $amountUsd,
                'rate_usd' => $lockedInvoice->rate_usd ?? $currency->rate ?? 0,
                'destination_address' => $destinationAddress,
                'destination_network_id' => $network->id,
                'status' => $autoApprove ? MerchantRefund::STATUS_PROCESSING : MerchantRefund::STATUS_PENDING,
                'requested_at' => now(),
                'requested_by' => $requestedBy ?? auth()->id(),
                'auto_approved' => $autoApprove,
                'approved_at' => $autoApprove ? now() : null,
            ]);

            // Update invoice status
            $lockedInvoice->transitionTo(MerchantInvoice::STATUS_REFUNDING, 'Refund initiated');

            // Deduct from merchant balance if this is a post-settlement refund
            if ($lockedInvoice->settled_at !== null) {
                $netRefundUsd = $amountUsd;
                $currentBalance = $merchant->available_balance_usd ?? '0';
                
                if (math_compare($currentBalance, $netRefundUsd) >= 0) {
                    $merchant->available_balance_usd = math_sub($currentBalance, $netRefundUsd);
                    $merchant->save();
                }
            }

            Log::info('Refund initiated', [
                'refund_id' => $refund->id,
                'invoice_id' => $lockedInvoice->id,
                'amount_crypto' => $amountCrypto,
                'amount_usd' => $amountUsd,
                'type' => $refundType,
            ]);

            // Notify about refund
            $this->notificationService->notifyRefundInitiated($refund);

            return $refund;
        }, 5);
    }

    /**
     * Initiate refund for an orphan payment
     */
    public function initiateOrphanPaymentRefund(
        MerchantOrphanPayment $orphanPayment,
        string $destinationAddress,
        ?int $requestedBy = null
    ): MerchantRefund {
        if ($orphanPayment->status !== MerchantOrphanPayment::STATUS_PENDING) {
            throw new \InvalidArgumentException('Orphan payment is not in pending status');
        }

        $currency = Currency::find($orphanPayment->currency_id);
        
        // Get network from the deposit address
        $address = $orphanPayment->address;
        if (!$address) {
            throw new \InvalidArgumentException('Deposit address not found for orphan payment');
        }
        $network = Network::find($address->network_id);

        if (!$currency || !$network) {
            throw new \InvalidArgumentException('Currency or network not found');
        }

        return DB::transaction(function () use ($orphanPayment, $currency, $network, $destinationAddress, $requestedBy) {
            $lockedOrphan = MerchantOrphanPayment::where('id', $orphanPayment->id)->lockForUpdate()->first();

            if ($lockedOrphan->status !== MerchantOrphanPayment::STATUS_PENDING) {
                throw new \InvalidArgumentException('Orphan payment status has changed');
            }

            $refund = MerchantRefund::create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $lockedOrphan->merchant_id,
                'orphan_payment_id' => $lockedOrphan->id,
                'refund_type' => MerchantRefund::TYPE_LATE_PAYMENT,
                'reason' => MerchantRefund::REASON_LATE_PAYMENT,
                'reason_details' => 'Refund for orphan/late payment',
                'amount_crypto' => $lockedOrphan->amount_crypto,
                'amount_usd' => $lockedOrphan->amount_usd,
                'rate_usd' => $currency->rate ?? 0,
                'destination_address' => $destinationAddress,
                'destination_network_id' => $network->id,
                'status' => MerchantRefund::STATUS_PENDING,
                'requested_at' => now(),
                'requested_by' => $requestedBy ?? auth()->id(),
            ]);

            // Update orphan payment status
            $lockedOrphan->status = 'refund_pending';
            $lockedOrphan->resolution_type = MerchantOrphanPayment::RESOLUTION_REFUND;
            $lockedOrphan->save();

            Log::info('Orphan payment refund initiated', [
                'refund_id' => $refund->id,
                'orphan_payment_id' => $lockedOrphan->id,
            ]);

            return $refund;
        }, 5);
    }

    /**
     * Approve a pending refund
     */
    public function approveRefund(MerchantRefund $refund, ?int $approvedBy = null, ?string $notes = null): MerchantRefund
    {
        if ($refund->status !== MerchantRefund::STATUS_PENDING) {
            throw new \InvalidArgumentException('Refund is not in pending status');
        }

        DB::transaction(function () use ($refund, $approvedBy, $notes) {
            $lockedRefund = MerchantRefund::where('id', $refund->id)->lockForUpdate()->first();
            
            if ($lockedRefund->status !== MerchantRefund::STATUS_PENDING) {
                throw new \InvalidArgumentException('Refund status has changed');
            }

            $lockedRefund->status = MerchantRefund::STATUS_PROCESSING;
            $lockedRefund->approved_at = now();
            $lockedRefund->approved_by = $approvedBy ?? auth()->id();
            $lockedRefund->admin_notes = $notes;
            $lockedRefund->save();
        }, 5);

        Log::info('Refund approved', [
            'refund_id' => $refund->id,
            'approved_by' => $approvedBy,
        ]);

        return $refund->fresh();
    }

    /**
     * Process an approved refund (send funds)
     */
    public function processRefund(MerchantRefund $refund): array
    {
        if ($refund->status !== MerchantRefund::STATUS_PROCESSING) {
            return ['success' => false, 'error' => 'Refund is not in processing status'];
        }

        return $this->transferService->processAndSendRefund($refund);
    }

    /**
     * Cancel a pending refund
     */
    public function cancelRefund(MerchantRefund $refund, ?string $reason = null): void
    {
        if ($refund->isFinal()) {
            throw new \InvalidArgumentException('Cannot cancel a final refund');
        }

        DB::transaction(function () use ($refund, $reason) {
            $lockedRefund = MerchantRefund::where('id', $refund->id)->lockForUpdate()->first();
            
            if ($lockedRefund->isFinal()) {
                throw new \InvalidArgumentException('Refund is already in a final state');
            }

            $lockedRefund->cancel();

            if ($lockedRefund->invoice_id) {
                $invoice = MerchantInvoice::where('id', $lockedRefund->invoice_id)->lockForUpdate()->first();
                if ($invoice && $invoice->status === MerchantInvoice::STATUS_REFUNDING) {
                    // Restore previous status
                    $invoice->transitionTo($invoice->previous_status ?? MerchantInvoice::STATUS_PAID, 'Refund cancelled: ' . $reason);
                }
            }

            if ($lockedRefund->orphan_payment_id) {
                $orphan = MerchantOrphanPayment::where('id', $lockedRefund->orphan_payment_id)->lockForUpdate()->first();
                if ($orphan) {
                    $orphan->status = MerchantOrphanPayment::STATUS_PENDING;
                    $orphan->resolution_type = null;
                    $orphan->save();
                }
            }

            // Restore merchant balance if it was deducted
            if ($lockedRefund->invoice_id) {
                $invoice = MerchantInvoice::find($lockedRefund->invoice_id);
                if ($invoice && $invoice->settled_at !== null) {
                    $merchant = Merchant::where('id', $lockedRefund->merchant_id)->lockForUpdate()->first();
                    if ($merchant) {
                        $merchant->available_balance_usd = math_sum(
                            $merchant->available_balance_usd ?? '0',
                            (string) $lockedRefund->amount_usd
                        );
                        $merchant->save();
                    }
                }
            }
        }, 5);

        Log::info('Refund cancelled', [
            'refund_id' => $refund->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Get pending refunds
     */
    public function getPendingRefunds(int $perPage = 20)
    {
        return MerchantRefund::where('status', MerchantRefund::STATUS_PENDING)
            ->with(['merchant', 'invoice', 'payment'])
            ->orderBy('requested_at', 'asc')
            ->paginate($perPage);
    }

    /**
     * Get approved refunds ready for processing
     */
    public function getRefundsForProcessing(int $limit = 50)
    {
        return MerchantRefund::where('status', MerchantRefund::STATUS_PROCESSING)
            ->whereNull('txn_hash')
            ->with(['invoice', 'invoice.currency', 'invoice.network'])
            ->orderBy('approved_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Validate that an invoice can be refunded
     */
    protected function validateRefundable(MerchantInvoice $invoice): void
    {
        $refundableStatuses = [
            MerchantInvoice::STATUS_PAID,
            MerchantInvoice::STATUS_OVERPAID,
            MerchantInvoice::STATUS_SETTLED,
        ];

        if (!in_array($invoice->status, $refundableStatuses)) {
            throw new \InvalidArgumentException('Invoice cannot be refunded in current status: ' . $invoice->status);
        }

        // Check if there's already a pending/processing refund
        $existingRefund = MerchantRefund::where('invoice_id', $invoice->id)
            ->whereNotIn('status', [MerchantRefund::STATUS_COMPLETED, MerchantRefund::STATUS_FAILED, MerchantRefund::STATUS_CANCELLED])
            ->first();

        if ($existingRefund) {
            throw new \InvalidArgumentException('A refund is already in progress for this invoice');
        }

        // Check if there are confirmed payments
        if (math_compare($invoice->amount_received_crypto ?? '0', '0') <= 0) {
            throw new \InvalidArgumentException('No funds to refund');
        }
    }

    /**
     * Get refund statistics for a merchant
     */
    public function getMerchantRefundStats(string $merchantId): array
    {
        $refunds = MerchantRefund::where('merchant_id', $merchantId);

        return [
            'total_refunds' => $refunds->count(),
            'total_refunded_usd' => (clone $refunds)->where('status', MerchantRefund::STATUS_COMPLETED)->sum('amount_usd'),
            'pending_refunds' => (clone $refunds)->where('status', MerchantRefund::STATUS_PENDING)->count(),
            'processing_refunds' => (clone $refunds)->where('status', MerchantRefund::STATUS_PROCESSING)->count(),
        ];
    }
}
