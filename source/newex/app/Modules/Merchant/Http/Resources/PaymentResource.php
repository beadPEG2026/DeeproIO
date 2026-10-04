<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'status' => $this->status,

            // Transaction details
            'txn_hash' => $this->txn_hash,
            'block_number' => $this->block_number,
            'from_address' => $this->from_address,
            'to_address' => $this->to_address,
            'memo' => $this->memo,

            // Amounts
            'amount_crypto' => $this->formatCrypto($this->amount_crypto),
            'amount_usd' => $this->formatAmount($this->amount_usd, 2),
            'rate_usd_at_detection' => $this->rate_usd_at_detection,

            // Confirmations
            'confirmations' => $this->confirmations,
            'required_confirmations' => $this->required_confirmations,
            'confirmation_progress' => $this->required_confirmations > 0
                ? min(100, round(($this->confirmations / $this->required_confirmations) * 100, 1))
                : 100,

            // Classification
            'classification' => $this->classification,
            'is_late_payment' => $this->is_late_payment,
            'counted_in_total' => $this->counted_in_total,

            // URLs
            'explorer_url' => $this->explorer_url,

            // Timestamps
            'detected_at' => $this->detected_at?->toIso8601String(),
            'first_confirmation_at' => $this->first_confirmation_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),

            // Related invoice (when loaded separately)
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
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
