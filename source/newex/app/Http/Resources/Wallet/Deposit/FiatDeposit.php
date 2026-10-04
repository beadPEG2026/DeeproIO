<?php

namespace App\Http\Resources\Wallet\Deposit;

use Illuminate\Http\Resources\Json\JsonResource;

class FiatDeposit extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'type' => $this->type,
            'deposit_id' => $this->deposit_id,
            'status' => $this->status,
            'logo' => ($this->currency?->logo_path ?: '/images/currency-placeholder.svg'),
            'amount' => $this->amount,
            'fee' => $this->fee,
            'rejected_reason' => $this->rejected_reason,
            'currency' => $this->currency->name,
            'symbol' => $this->currency->symbol,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
