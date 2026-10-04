<?php

namespace App\Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
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
            'refund_type' => $this->refund_type,
            'reason' => $this->reason,
            'status' => $this->status,
            'amount_crypto' => $this->amount_crypto,
            'amount_usd' => $this->amount_usd,
            'rate_usd' => $this->rate_usd,
            'destination_address' => $this->destination_address,
            'txn_hash' => $this->txn_hash,
            'network_fee_crypto' => $this->network_fee_crypto,
            'network_fee_usd' => $this->network_fee_usd,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'broadcast_at' => $this->broadcast_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            
            // Relationships
            'invoice' => $this->whenLoaded('invoice', function () {
                return [
                    'id' => $this->invoice->id,
                    'external_id' => $this->invoice->external_id,
                    'amount_usd' => $this->invoice->amount_usd,
                    'status' => $this->invoice->status,
                ];
            }),
            'currency' => $this->whenLoaded('invoice', function () {
                if ($this->invoice && $this->invoice->currencyModel) {
                    return [
                        'symbol' => $this->invoice->currencyModel->symbol,
                        'name' => $this->invoice->currencyModel->name,
                    ];
                }
                return null;
            }),
        ];
    }
}
