<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportedAssetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'asset_code' => $this->asset_code,

            // Currency info
            'currency' => [
                'symbol' => $this->currency?->symbol,
                'name' => $this->currency?->name,
                'icon_url' => $this->currency?->icon_url,
            ],

            // Network info
            'network' => [
                'name' => $this->network?->name,
                'slug' => $this->network?->slug,
            ],

            // Limits
            'min_amount_usd' => $this->min_amount_usd,
            'max_amount_usd' => $this->max_amount_usd,

            // Confirmations
            'required_confirmations' => $this->required_confirmations,
            'safe_confirmations' => $this->safe_confirmations,

            // Display
            'precision' => $this->precision,
            'display_decimals' => $this->display_decimals,

            // Address type
            'address_type' => $this->address_type,
            'requires_memo' => $this->address_type !== 'unique',

            // Estimated rate (when available)
            'estimated_rate' => $this->when(isset($this->estimated_rate), function () {
                return [
                    'rate_usd' => $this->estimated_rate['rate_usd'] ?? null,
                    'amount_crypto' => $this->estimated_rate['amount_crypto_display'] ?? null,
                    'available' => $this->estimated_rate['available'] ?? false,
                ];
            }),
        ];
    }
}
