<?php

namespace App\Http\Resources\Wallet\Withdrawal;

use Illuminate\Http\Resources\Json\JsonResource;

class FiatWithdrawal extends JsonResource
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
            'withdrawal_id' => $this->withdrawal_id,
            'logo' => ($this->currency?->logo_path ?: '/images/currency-placeholder.svg'),
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency->name,
            'symbol' => $this->currency->symbol,
            'fee' => $this->fee,
            'rejected_reason' => $this->rejected_reason,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
