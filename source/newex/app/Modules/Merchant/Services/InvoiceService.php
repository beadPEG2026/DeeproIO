<?php

namespace App\Modules\Merchant\Services;

use App\Models\Currency\Currency;
use App\Modules\Merchant\Enums\InvoiceStatus;
use App\Modules\Merchant\Enums\WebhookEventType;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantInvoiceTimeline;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Services\PricingService;
use App\Modules\Merchant\Services\AddressManagerService;
use App\Modules\Merchant\Services\WebhookDispatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InvoiceService
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected PricingService $pricingService,
        protected AddressManagerService $addressManager,
        protected WebhookDispatcherService $webhookDispatcher,
        protected MerchantNotificationService $notificationService
    ) {}

    /**
     * Create a new invoice
     */
    public function createInvoice(Merchant $merchant, array $data): MerchantInvoice
    {
        // Basic validation before transaction (non-critical checks)
        $this->validateMerchantCanCreateInvoice($merchant, $data);

        return DB::transaction(function () use ($merchant, $data) {
            // Idempotency check INSIDE transaction with lock to prevent duplicates
            if (!empty($data['idempotency_key'])) {
                $existing = MerchantInvoice::where('merchant_id', $merchant->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                    
                if ($existing) {
                    return $existing;
                }
            }
            // Calculate expiration times
            $selectionExpiry = config('merchant_acquiring.invoice.selection_expiry', 3600);
            $expiresAt = now()->addSeconds($selectionExpiry);

            // Create the invoice
            $invoice = MerchantInvoice::create([
                'id' => Str::uuid()->toString(),
                'merchant_id' => $merchant->id,
                'external_id' => $data['external_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'status' => InvoiceStatus::AWAITING_SELECTION->value,
                'amount_usd' => $data['amount'],
                'currency' => $data['currency'] ?? 'USD',
                'description' => $data['description'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_metadata' => $data['customer_metadata'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'line_items' => $data['line_items'] ?? null,
                'redirect_url' => $data['redirect_url'] ?? null,
                'cancel_url' => $data['cancel_url'] ?? null,
                'webhook_url' => $data['webhook_url'] ?? $merchant->default_webhook_url,
                'expires_at' => $expiresAt,
                'selection_expires_at' => $expiresAt,
                'fee_percent' => $merchant->fee_percent,
                'source' => $data['source'] ?? 'api',
                'source_ip' => $data['source_ip'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'environment' => $data['environment'] ?? 'live',
            ]);

            // Record timeline event
            MerchantInvoiceTimeline::record(
                $invoice,
                MerchantInvoiceTimeline::EVENT_CREATED,
                $data['source'] ?? MerchantInvoiceTimeline::SOURCE_API,
                null,
                InvoiceStatus::AWAITING_SELECTION->value,
                ['amount_usd' => $data['amount']],
                MerchantInvoiceTimeline::ACTOR_MERCHANT,
                null,
                $data['source_ip'] ?? null
            );

            // Dispatch webhook for invoice.created (optional, based on config)
            if (in_array('invoice.created', config('merchant_acquiring.webhook.events', []))) {
                $this->webhookDispatcher->dispatch(
                    $invoice,
                    WebhookEventType::INVOICE_CREATED
                );
            }

            // Send email notification to merchant
            $this->notificationService->notifyInvoiceCreated($invoice);

            return $invoice;
        });
    }

    /**
     * Select currency for an invoice (locks rate and assigns address)
     */
    public function selectCurrency(MerchantInvoice $invoice, string $currencySymbol, ?int $networkId = null): MerchantInvoice
    {
        // Validate invoice state
        if ($invoice->status !== InvoiceStatus::AWAITING_SELECTION->value) {
            throw new InvalidArgumentException(
                "Cannot select currency for invoice in status: {$invoice->status}"
            );
        }

        // Check if invoice is expired
        if ($invoice->isExpired()) {
            $this->expireInvoice($invoice, 'Selection timeout');
            throw new InvalidArgumentException('Invoice has expired');
        }

        // Get the currency enabled for merchant
        $currency = Currency::where('symbol', $currencySymbol)
            ->where('is_merchant', true)
            ->where('status', true)
            ->with('networks')
            ->first();

        if (!$currency) {
            throw new InvalidArgumentException("Currency not available: {$currencySymbol}");
        }

        // Validate network
        $enabledNetworks = $currency->merchant_enabled_networks ?? [];
        if ($networkId) {
            // Check if network is enabled for this currency's merchant acquiring
            if (!empty($enabledNetworks) && !in_array($networkId, $enabledNetworks)) {
                throw new InvalidArgumentException("Network not enabled for merchant acquiring");
            }
            // Check if currency has this network
            if (!$currency->networks->contains('id', $networkId)) {
                throw new InvalidArgumentException("Network not available for this currency");
            }
        } else {
            // Use first available network
            if (!empty($enabledNetworks)) {
                $networkId = $enabledNetworks[0];
            } else {
                $networkId = $currency->networks->first()?->id;
            }
        }

        // Validate amount is within limits
        $amount = (float) $invoice->amount_usd;
        if ($amount < $currency->merchant_min_amount_usd || $amount > $currency->merchant_max_amount_usd) {
            throw new InvalidArgumentException(
                "Amount {$invoice->amount_usd} USD is outside limits for {$currencySymbol}"
            );
        }

        return DB::transaction(function () use ($invoice, $currency, $networkId) {
            // Lock the exchange rate
            $rateData = $this->pricingService->lockRate($invoice, $currency);

            // Assign deposit address for the specific network
            $addressData = $this->addressManager->assignAddress($invoice, $currency, $networkId);

            // Calculate payment window expiration
            $paymentExpiry = config('merchant_acquiring.invoice.payment_expiry', 1800);
            $paymentExpiresAt = now()->addSeconds($paymentExpiry);

            // Calculate fee amounts
            $feeAmountCrypto = bcmul($rateData['amount_crypto'], $invoice->fee_percent / 100, 18);
            $feeAmountUsd = bcmul($invoice->amount_usd, $invoice->fee_percent / 100, 2);
            $netAmountCrypto = bcsub($rateData['amount_crypto'], $feeAmountCrypto, 18);
            $netAmountUsd = bcsub($invoice->amount_usd, $feeAmountUsd, 2);

            // Calculate tolerance bounds
            $tolerancePercent = config('merchant_acquiring.invoice.underpayment_tolerance_percent', 1.0);
            $minAmount = bcmul($rateData['amount_crypto'], (100 - $tolerancePercent) / 100, 18);
            $maxTolerance = config('merchant_acquiring.invoice.overpayment_tolerance_percent', 5.0);
            $maxAmount = bcmul($rateData['amount_crypto'], (100 + $maxTolerance) / 100, 18);

            // Update invoice
            $invoice->update([
                'currency_id' => $currency->id,
                'network_id' => $networkId,
                'status' => InvoiceStatus::AWAITING_PAYMENT->value,
                'previous_status' => InvoiceStatus::AWAITING_SELECTION->value,
                'amount_crypto' => $rateData['amount_crypto'],
                'amount_crypto_min' => $minAmount,
                'amount_crypto_max' => $maxAmount,
                'rate_usd' => $rateData['rate_usd'],
                'rate_bid' => $rateData['bid_price'] ?? null,
                'rate_ask' => $rateData['ask_price'] ?? null,
                'rate_source' => $rateData['rate_source'],
                'rate_locked_at' => now(),
                'rate_expires_at' => $rateData['expires_at'],
                'deposit_address' => $addressData['address'],
                'deposit_memo' => $addressData['memo'] ?? null,
                'deposit_address_id' => $addressData['address_id'],
                'payment_expires_at' => $paymentExpiresAt,
                'currency_selected_at' => now(),
                'fee_amount_crypto' => $feeAmountCrypto,
                'fee_amount_usd' => $feeAmountUsd,
                'net_amount_crypto' => $netAmountCrypto,
                'net_amount_usd' => $netAmountUsd,
            ]);

            // Record timeline
            MerchantInvoiceTimeline::record(
                $invoice,
                MerchantInvoiceTimeline::EVENT_CURRENCY_SELECTED,
                MerchantInvoiceTimeline::SOURCE_WIDGET,
                InvoiceStatus::AWAITING_SELECTION->value,
                InvoiceStatus::AWAITING_PAYMENT->value,
                [
                    'currency' => $currency->symbol,
                    'rate_usd' => $rateData['rate_usd'],
                    'amount_crypto' => $rateData['amount_crypto'],
                    'deposit_address' => $addressData['address'],
                ],
                MerchantInvoiceTimeline::ACTOR_BUYER
            );

            // Dispatch webhook
            $this->webhookDispatcher->dispatch(
                $invoice,
                WebhookEventType::INVOICE_PENDING
            );

            return $invoice->fresh();
        });
    }

    /**
     * Process a detected payment
     */
    public function processPaymentDetected(MerchantInvoice $invoice, array $paymentData): void
    {
        DB::transaction(function () use ($invoice, $paymentData) {
            // Update invoice status if needed
            if ($invoice->status === InvoiceStatus::AWAITING_PAYMENT->value) {
                $invoice->update([
                    'status' => InvoiceStatus::DETECTING->value,
                    'previous_status' => $invoice->status,
                    'first_payment_at' => $invoice->first_payment_at ?? now(),
                ]);

                MerchantInvoiceTimeline::record(
                    $invoice,
                    MerchantInvoiceTimeline::EVENT_PAYMENT_DETECTED,
                    MerchantInvoiceTimeline::SOURCE_BLOCKCHAIN,
                    InvoiceStatus::AWAITING_PAYMENT->value,
                    InvoiceStatus::DETECTING->value,
                    $paymentData
                );

                $this->webhookDispatcher->dispatch(
                    $invoice,
                    WebhookEventType::INVOICE_PAYMENT_DETECTING,
                    ['payment' => $paymentData]
                );
            }
        });
    }

    /**
     * Process payment confirmation update
     */
    public function processPaymentConfirming(MerchantInvoice $invoice, int $confirmations, int $required): void
    {
        if ($invoice->status === InvoiceStatus::DETECTING->value) {
            $invoice->update([
                'status' => InvoiceStatus::CONFIRMING->value,
                'previous_status' => $invoice->status,
            ]);

            MerchantInvoiceTimeline::record(
                $invoice,
                MerchantInvoiceTimeline::EVENT_PAYMENT_CONFIRMING,
                MerchantInvoiceTimeline::SOURCE_BLOCKCHAIN,
                InvoiceStatus::DETECTING->value,
                InvoiceStatus::CONFIRMING->value,
                ['confirmations' => $confirmations, 'required' => $required]
            );

            $this->webhookDispatcher->dispatch(
                $invoice,
                WebhookEventType::INVOICE_CONFIRMING,
                ['confirmations' => $confirmations, 'required_confirmations' => $required]
            );
        }
    }

    /**
     * Complete invoice payment
     */
    public function completePayment(MerchantInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            // Lock the invoice to prevent double completion
            $lockedInvoice = MerchantInvoice::where('id', $invoice->id)->lockForUpdate()->first();
            
            if (!$lockedInvoice) {
                throw new \RuntimeException('Invoice not found');
            }

            // Verify invoice is in a completable state
            if ($lockedInvoice->isFinal()) {
                return; // Already completed
            }

            // Classify the payment
            $classification = $this->classifyPayment($lockedInvoice);

            $newStatus = match ($classification['type']) {
                'exact' => InvoiceStatus::PAID,
                'underpaid' => InvoiceStatus::UNDERPAID,
                'overpaid' => InvoiceStatus::OVERPAID,
                default => InvoiceStatus::PAID,
            };

            $lockedInvoice->update([
                'status' => $newStatus->value,
                'previous_status' => $lockedInvoice->status,
                'payment_classification' => $classification['type'],
                'payment_variance_percent' => $classification['variance_percent'],
                'paid_at' => now(),
            ]);

            // Record timeline
            MerchantInvoiceTimeline::record(
                $lockedInvoice,
                MerchantInvoiceTimeline::EVENT_PAID,
                MerchantInvoiceTimeline::SOURCE_BLOCKCHAIN,
                $lockedInvoice->previous_status,
                $newStatus->value,
                [
                    'classification' => $classification['type'],
                    'variance_percent' => $classification['variance_percent'],
                    'amount_received_crypto' => $lockedInvoice->amount_received_crypto,
                ]
            );

            // Dispatch appropriate webhook
            $eventType = match ($newStatus) {
                InvoiceStatus::PAID => WebhookEventType::INVOICE_PAID,
                InvoiceStatus::UNDERPAID => WebhookEventType::INVOICE_UNDERPAID,
                InvoiceStatus::OVERPAID => WebhookEventType::INVOICE_OVERPAID,
                default => WebhookEventType::INVOICE_PAID,
            };

            $this->webhookDispatcher->dispatch($lockedInvoice, $eventType, [
                'classification' => $classification,
            ]);

            // Release address back to pool
            $this->addressManager->releaseAddress($lockedInvoice);

            // Lock merchant for balance updates
            $lockedMerchant = Merchant::where('id', $lockedInvoice->merchant_id)->lockForUpdate()->first();
            
            if ($lockedMerchant) {
                // Update merchant statistics using precision math
                $amountReceivedUsd = (string) ($lockedInvoice->amount_received_usd ?? '0');
                $feeAmountUsd = (string) ($lockedInvoice->fee_amount_usd ?? '0');
                
                $lockedMerchant->incrementInvoiceStats($amountReceivedUsd, $feeAmountUsd);

                // Credit earnings to merchant's available balance using precision math
                $netEarnings = math_sub($amountReceivedUsd, $feeAmountUsd);
                if (math_compare($netEarnings, '0') > 0) {
                    $lockedMerchant->addEarnings($netEarnings);
                }
            }

            // Send email notification to merchant about paid invoice
            $this->notificationService->notifyInvoicePaid($lockedInvoice);
        }, 5);
    }

    /**
     * Expire an invoice
     */
    public function expireInvoice(MerchantInvoice $invoice, ?string $reason = null): void
    {
        if ($invoice->isFinal()) {
            return;
        }

        DB::transaction(function () use ($invoice, $reason) {
            $previousStatus = $invoice->status;

            $invoice->update([
                'status' => InvoiceStatus::EXPIRED->value,
                'previous_status' => $previousStatus,
                'status_reason' => $reason ?? 'Invoice expired',
                'expired_at' => now(),
            ]);

            MerchantInvoiceTimeline::record(
                $invoice,
                MerchantInvoiceTimeline::EVENT_EXPIRED,
                MerchantInvoiceTimeline::SOURCE_SYSTEM,
                $previousStatus,
                InvoiceStatus::EXPIRED->value,
                ['reason' => $reason]
            );

            $this->webhookDispatcher->dispatch($invoice, WebhookEventType::INVOICE_EXPIRED);

            // Release address if assigned
            if ($invoice->deposit_address_id) {
                $this->addressManager->releaseAddress($invoice);
            }

            // Send email notification to merchant about expired invoice
            $this->notificationService->notifyInvoiceExpired($invoice);
        });
    }

    /**
     * Cancel an invoice
     */
    public function cancelInvoice(MerchantInvoice $invoice, ?string $reason = null, ?int $cancelledBy = null): void
    {
        if (!$invoice->canBeCancelled()) {
            throw new InvalidArgumentException(
                "Invoice cannot be cancelled in status: {$invoice->status}"
            );
        }

        DB::transaction(function () use ($invoice, $reason, $cancelledBy) {
            $previousStatus = $invoice->status;

            $invoice->update([
                'status' => InvoiceStatus::CANCELLED->value,
                'previous_status' => $previousStatus,
                'cancellation_reason' => $reason,
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => now(),
            ]);

            MerchantInvoiceTimeline::record(
                $invoice,
                MerchantInvoiceTimeline::EVENT_CANCELLED,
                MerchantInvoiceTimeline::SOURCE_API,
                $previousStatus,
                InvoiceStatus::CANCELLED->value,
                ['reason' => $reason],
                MerchantInvoiceTimeline::ACTOR_MERCHANT,
                $cancelledBy
            );

            $this->webhookDispatcher->dispatch($invoice, WebhookEventType::INVOICE_CANCELLED);

            // Release address if assigned
            if ($invoice->deposit_address_id) {
                $this->addressManager->releaseAddress($invoice);
            }
        });
    }

    /**
     * Settle an invoice (credit merchant balance)
     */
    public function settleInvoice(MerchantInvoice $invoice): void
    {
        if (!in_array($invoice->status, [
            InvoiceStatus::PAID->value,
            InvoiceStatus::OVERPAID->value,
        ])) {
            throw new InvalidArgumentException(
                "Invoice cannot be settled in status: {$invoice->status}"
            );
        }

        DB::transaction(function () use ($invoice) {
            $previousStatus = $invoice->status;

            $invoice->update([
                'status' => InvoiceStatus::SETTLED->value,
                'previous_status' => $previousStatus,
                'settled_at' => now(),
            ]);

            MerchantInvoiceTimeline::record(
                $invoice,
                MerchantInvoiceTimeline::EVENT_SETTLED,
                MerchantInvoiceTimeline::SOURCE_SYSTEM,
                $previousStatus,
                InvoiceStatus::SETTLED->value,
                [
                    'net_amount_crypto' => $invoice->net_amount_crypto,
                    'net_amount_usd' => $invoice->net_amount_usd,
                ]
            );

            $this->webhookDispatcher->dispatch($invoice, WebhookEventType::INVOICE_SETTLED);
        });
    }

    /**
     * Extend rate validity
     */
    public function extendRate(MerchantInvoice $invoice): MerchantInvoice
    {
        $maxExtensions = config('merchant_acquiring.invoice.max_rate_extensions', 2);

        if ($invoice->rate_extended_count >= $maxExtensions) {
            throw new InvalidArgumentException('Maximum rate extensions reached');
        }

        if ($invoice->status !== InvoiceStatus::AWAITING_PAYMENT->value) {
            throw new InvalidArgumentException('Rate can only be extended while awaiting payment');
        }

        $extensionDuration = config('merchant_acquiring.invoice.rate_extension_duration', 300);

        $invoice->update([
            'rate_expires_at' => now()->addSeconds($extensionDuration),
            'rate_extended_count' => $invoice->rate_extended_count + 1,
            'rate_extended_at' => now(),
        ]);

        MerchantInvoiceTimeline::record(
            $invoice,
            MerchantInvoiceTimeline::EVENT_RATE_EXTENDED,
            MerchantInvoiceTimeline::SOURCE_WIDGET,
            null,
            null,
            [
                'extension_number' => $invoice->rate_extended_count,
                'new_expiry' => $invoice->rate_expires_at->toIso8601String(),
            ],
            MerchantInvoiceTimeline::ACTOR_BUYER
        );

        return $invoice->fresh();
    }

    /**
     * Classify payment amount using precision math
     */
    protected function classifyPayment(MerchantInvoice $invoice): array
    {
        $received = (string) ($invoice->amount_received_crypto ?? '0');
        $expected = (string) ($invoice->amount_crypto ?? '0');

        if (math_compare($expected, '0') == 0) {
            return ['type' => 'exact', 'variance_percent' => '0'];
        }

        // Calculate variance: ((received - expected) / expected) * 100
        $difference = math_sub($received, $expected);
        $variancePercent = math_multiply(math_divide($difference, $expected), '100');
        $tolerancePercent = (string) config('merchant_acquiring.payment.exact_tolerance_percent', 1.0);

        // Check if within tolerance
        $absVariance = ltrim($variancePercent, '-'); // Simple absolute value for comparison
        if (math_compare($absVariance, $tolerancePercent) <= 0) {
            return ['type' => 'exact', 'variance_percent' => $variancePercent];
        }

        // Check if underpaid (negative variance beyond tolerance)
        $negativeTolerance = '-' . $tolerancePercent;
        if (math_compare($variancePercent, $negativeTolerance) < 0) {
            return ['type' => 'underpaid', 'variance_percent' => $variancePercent];
        }

        return ['type' => 'overpaid', 'variance_percent' => $variancePercent];
    }

    /**
     * Validate merchant can create invoice
     */
    protected function validateMerchantCanCreateInvoice(Merchant $merchant, array $data): void
    {
        if (!$merchant->isActive()) {
            throw new InvalidArgumentException('Merchant account is not active');
        }

        $amount = (string) $data['amount'];

        // Check minimum amount using precision math
        $minAmount = max((float) $merchant->min_invoice_amount_usd, config('merchant_acquiring.invoice.min_amount_usd', 1.0));
        if (math_compare($amount, (string) $minAmount) < 0) {
            throw new InvalidArgumentException("Amount must be at least {$minAmount} USD");
        }

        // Check single invoice limit using precision math
        if (math_compare($amount, (string) $merchant->single_invoice_limit_usd) > 0) {
            throw new InvalidArgumentException(
                "Amount exceeds single invoice limit of {$merchant->single_invoice_limit_usd} USD"
            );
        }

        // Check daily volume limit using precision math
        $todayVolume = (string) $this->invoiceRepository->getDailyVolume($merchant->id);
        $projectedDailyVolume = math_sum($todayVolume, $amount);
        if (math_compare($projectedDailyVolume, (string) $merchant->daily_volume_limit_usd) > 0) {
            throw new InvalidArgumentException('Daily volume limit would be exceeded');
        }

        // Check monthly volume limit using precision math
        $monthlyVolume = (string) $this->invoiceRepository->getMonthlyVolume($merchant->id);
        $projectedMonthlyVolume = math_sum($monthlyVolume, $amount);
        if (math_compare($projectedMonthlyVolume, (string) $merchant->monthly_volume_limit_usd) > 0) {
            throw new InvalidArgumentException('Monthly volume limit would be exceeded');
        }
    }

    /**
     * Get invoice with validation
     */
    public function getInvoice(string $invoiceId, ?string $merchantId = null): MerchantInvoice
    {
        $invoice = $this->invoiceRepository->find($invoiceId);

        if (!$invoice) {
            throw new InvalidArgumentException('Invoice not found');
        }

        if ($merchantId && $invoice->merchant_id !== $merchantId) {
            throw new InvalidArgumentException('Invoice not found');
        }

        return $invoice;
    }

    /**
     * Generate widget token for invoice
     */
    public function generateWidgetToken(MerchantInvoice $invoice): string
    {
        $payload = [
            'invoice_id' => $invoice->id,
            'merchant_id' => $invoice->merchant_id,
            'exp' => $invoice->expires_at->timestamp,
            'iat' => now()->timestamp,
        ];

        // Simple HMAC-based token (in production, use JWT)
        $data = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $data, config('app.key'));

        return 'wgt_' . $data . '.' . $signature;
    }
}
