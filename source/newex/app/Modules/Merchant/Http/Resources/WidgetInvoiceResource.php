<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WidgetInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Simplified invoice data for widget display (public-facing)
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,

            // Amounts (public info only)
            'amount_usd' => $this->formatAmount($this->amount_usd, 2),
            'amount_usd_display' => '$' . number_format((float) $this->amount_usd, 2),
            'amount_crypto' => $this->amount_crypto ? $this->formatCrypto($this->amount_crypto) : null,
            'amount_received_crypto' => $this->amount_received_crypto ? $this->formatCrypto($this->amount_received_crypto) : null,

            // Currency (if selected)
            'currency' => $this->when($this->currencyModel, function () {
                return [
                    'code' => $this->currencyModel->symbol,
                    'symbol' => $this->currencyModel->symbol,
                    'name' => $this->currencyModel->name,
                    'network' => $this->network?->name,
                    'icon_url' => $this->currencyModel->icon_url,
                ];
            }),

            // Payment details (if currency selected)
            'deposit_address' => $this->deposit_address,
            'deposit_memo' => $this->deposit_memo,

            // Timing
            'expires_at' => $this->expires_at?->toIso8601String(),
            'payment_expires_at' => $this->payment_expires_at?->toIso8601String(),
            'rate_expires_at' => $this->rate_expires_at?->toIso8601String(),

            // Display info
            'description' => $this->description,
            'merchant_name' => $this->merchant?->business_name,
            'merchant_logo' => $this->merchant?->logo_url,

            // Confirmations
            'confirmations' => $this->when($this->currencyModel, function () {
                return [
                    'current' => $this->payments()->sum('confirmations'),
                    'required' => $this->currencyModel->merchant_confirmations ?? 3,
                ];
            }),

            // URLs
            'redirect_url' => $this->redirect_url,
            'cancel_url' => $this->cancel_url,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }

    /**
     * Format amount with specific decimals
     */
    protected function formatAmount($value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }

    /**
     * Format crypto amount (trim trailing zeros)
     */
    protected function formatCrypto($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 18, '.', ''), '0'), '.');
    }
}
