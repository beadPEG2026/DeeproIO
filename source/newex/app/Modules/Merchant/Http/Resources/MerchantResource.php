<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
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
            'business_name' => $this->business_name,
            'business_email' => $this->business_email,
            'status' => $this->status,
            'verification_status' => $this->verification_status,

            // Settings
            'default_webhook_url' => $this->default_webhook_url,
            'webhook_events' => $this->webhook_events,
            'ip_whitelist' => $this->ip_whitelist,

            // Limits
            'fee_percent' => $this->fee_percent,
            'daily_volume_limit_usd' => $this->daily_volume_limit_usd,
            'monthly_volume_limit_usd' => $this->monthly_volume_limit_usd,
            'single_invoice_limit_usd' => $this->single_invoice_limit_usd,
            'min_invoice_amount_usd' => $this->min_invoice_amount_usd,

            // Statistics
            'total_invoices' => $this->total_invoices,
            'paid_invoices' => $this->paid_invoices,
            'total_volume_usd' => $this->total_volume_usd,
            'total_fees_usd' => $this->total_fees_usd,

            // Features
            'features' => $this->features,
            'auto_payout_enabled' => $this->auto_payout_enabled,
            'auto_payout_threshold' => $this->auto_payout_threshold,
            'auto_payout_address' => $this->auto_payout_address,

            // Brand
            'website_url' => $this->website_url,
            'logo_url' => $this->logo_url,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
