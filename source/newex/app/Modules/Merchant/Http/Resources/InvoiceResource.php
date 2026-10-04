<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'external_id' => $this->external_id,
            'status' => $this->status,
            'previous_status' => $this->previous_status,

            // Amounts
            'amount_usd' => $this->formatAmount($this->amount_usd, 2),
            'amount_crypto' => $this->amount_crypto ? $this->formatCrypto($this->amount_crypto) : null,
            'amount_received_crypto' => $this->amount_received_crypto ? $this->formatCrypto($this->amount_received_crypto) : null,
            'amount_received_usd' => $this->amount_received_usd ? $this->formatAmount($this->amount_received_usd, 2) : null,

            // Currency details
            'currency' => $this->when($this->currencyModel, function () {
                return [
                    'code' => $this->currencyModel->symbol,
                    'symbol' => $this->currencyModel->symbol,
                    'name' => $this->currencyModel->name,
                    'network' => $this->network?->name,
                    'network_slug' => $this->network?->slug,
                ];
            }),

            // Rate information
            'rate_usd' => $this->rate_usd ? $this->formatAmount($this->rate_usd, 8) : null,
            'rate_source' => $this->rate_source,
            'rate_locked_at' => $this->rate_locked_at?->toIso8601String(),
            'rate_expires_at' => $this->rate_expires_at?->toIso8601String(),
            'rate_extended_count' => $this->rate_extended_count,

            // Payment details
            'deposit_address' => $this->deposit_address,
            'deposit_memo' => $this->deposit_memo,

            // Fees
            'fee_percent' => $this->fee_percent,
            'fee_amount_usd' => $this->fee_amount_usd ? $this->formatAmount($this->fee_amount_usd, 2) : null,
            'fee_amount_crypto' => $this->fee_amount_crypto ? $this->formatCrypto($this->fee_amount_crypto) : null,
            'net_amount_usd' => $this->net_amount_usd ? $this->formatAmount($this->net_amount_usd, 2) : null,
            'net_amount_crypto' => $this->net_amount_crypto ? $this->formatCrypto($this->net_amount_crypto) : null,

            // Customer details
            'customer_email' => $this->customer_email,
            'customer_name' => $this->customer_name,
            'description' => $this->description,

            // Line items
            'line_items' => $this->line_items,

            // Metadata
            'metadata' => $this->metadata,
            'customer_metadata' => $this->customer_metadata,

            // URLs
            'redirect_url' => $this->redirect_url,
            'cancel_url' => $this->cancel_url,
            'webhook_url' => $this->webhook_url,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'payment_expires_at' => $this->payment_expires_at?->toIso8601String(),
            'currency_selected_at' => $this->currency_selected_at?->toIso8601String(),
            'first_payment_at' => $this->first_payment_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            // Payment classification
            'payment_classification' => $this->payment_classification,
            'payment_variance_percent' => $this->payment_variance_percent,

            // Related data
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'timeline' => TimelineResource::collection($this->whenLoaded('timeline')),

            // Environment
            'environment' => $this->environment,
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
