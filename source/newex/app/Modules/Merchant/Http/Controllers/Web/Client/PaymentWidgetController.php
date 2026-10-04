<?php

namespace App\Modules\Merchant\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PaymentWidgetController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Hosted checkout page
     */
    public function checkout(string $invoiceId)
    {
        $invoice = MerchantInvoice::with([
            'merchant',
            'currencyModel.file',
            'network',
        ])->find($invoiceId);

        if (!$invoice) {
            return Inertia::render('Merchant/Widget/NotFound');
        }

        // Check and update expired status if needed
        $invoice->checkAndExpire();

        // Check if invoice is already complete
        if ($invoice->isFinal()) {
            return $this->renderCompletedState($invoice);
        }

        // Check if expired (this handles edge cases)
        if ($invoice->isExpired()) {
            $invoice->checkAndExpire(); // Update status in DB
            return Inertia::render('Merchant/Widget/Expired', [
                'invoice' => $this->getPublicInvoiceData($invoice),
                'merchant' => $this->getMerchantBranding($invoice->merchant),
            ]);
        }

        // Generate widget token
        $widgetToken = $this->invoiceService->generateWidgetToken($invoice);

        return Inertia::render('Merchant/Widget/Checkout', [
            'invoice' => $this->getPublicInvoiceData($invoice),
            'merchant' => $this->getMerchantBranding($invoice->merchant),
            'widgetToken' => $widgetToken,
            'apiEndpoint' => url('/widget/v1'),
            'pollInterval' => config('merchant_acquiring.widget.poll_interval', 3000),
        ]);
    }

    /**
     * Generate QR code
     */
    public function generateQr(string $data): Response
    {
        $decodedData = base64_decode($data);

        if (!$decodedData) {
            abort(400, 'Invalid QR data');
        }

        $qrCode = QrCode::format('svg')
            ->size(250)
            ->margin(1)
            ->generate($decodedData);

        return response($qrCode, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Render completed state based on invoice status
     */
    protected function renderCompletedState(MerchantInvoice $invoice)
    {
        $template = match ($invoice->status) {
            'paid', 'settled' => 'Merchant/Widget/Success',
            'overpaid' => 'Merchant/Widget/Overpaid',
            'underpaid' => 'Merchant/Widget/Underpaid',
            'expired' => 'Merchant/Widget/Expired',
            'cancelled' => 'Merchant/Widget/Cancelled',
            'failed' => 'Merchant/Widget/Failed',
            default => 'Merchant/Widget/Complete',
        };

        return Inertia::render($template, [
            'invoice' => $this->getPublicInvoiceData($invoice),
            'merchant' => $this->getMerchantBranding($invoice->merchant),
        ]);
    }

    /**
     * Get public invoice data (safe for frontend)
     */
    protected function getPublicInvoiceData(MerchantInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'status' => $invoice->status,
            'amount_usd' => $invoice->amount_usd,
            'amount_usd_display' => '$' . number_format((float) $invoice->amount_usd, 2),
            'description' => $invoice->description,
            'customer_email' => $invoice->customer_email,
            'currency_selected' => $invoice->currency_id !== null,
            'currency' => $invoice->currencyModel ? [
                'code' => $invoice->currencyModel->symbol,
                'symbol' => $invoice->currencyModel->symbol,
                'name' => $invoice->currencyModel->name,
                'network' => $invoice->network?->name,
                'icon_url' => $invoice->currencyModel ? url($invoice->currencyModel->logo_path) : null,
            ] : null,
            'amount_crypto' => $invoice->amount_crypto,
            'amount_crypto_display' => $invoice->amount_crypto
                ? rtrim(rtrim($invoice->amount_crypto, '0'), '.')
                : null,
            'deposit_address' => $invoice->deposit_address,
            'deposit_memo' => $invoice->deposit_memo,
            'redirect_url' => $invoice->redirect_url,
            'cancel_url' => $invoice->cancel_url,
            'expires_at' => $invoice->expires_at?->toIso8601String(),
            'payment_expires_at' => $invoice->payment_expires_at?->toIso8601String(),
            'rate_expires_at' => $invoice->rate_expires_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }

    /**
     * Get merchant branding info
     */
    protected function getMerchantBranding($merchant): array
    {
        return [
            'name' => $merchant->business_name,
            'logo_url' => $merchant->logo_url,
            'website_url' => $merchant->website_url,
            'support_email' => $merchant->support_email ?? $merchant->business_email,
        ];
    }
}
