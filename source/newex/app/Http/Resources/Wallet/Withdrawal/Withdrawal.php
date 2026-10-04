<?php

namespace App\Http\Resources\Wallet\Withdrawal;

use Illuminate\Http\Resources\Json\JsonResource;

class Withdrawal extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        switch ($this->status) {
            case 'confirmed_provider':
                $status = 'completed'; break;
            case 'rejected':
                $status = 'rejected'; break;
            case 'failed':
                $status = 'failed'; break;
            default:
                $status = 'pending';
        }

        return [
            'withdrawal_id' => $this->withdrawal_id,
            'status' => $status,
            'logo' => ($this->currency?->logo_path ?: '/images/currency-placeholder.svg'),
            'txn' => $this->txn,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'currency' => $this->currency->name,
            'explorer' => $this->txn_link,
            'symbol' => $this->currency->symbol,
            'address' => $this->address,
            'rejected_reason' => $this->rejected_reason,
            'payment_id' => $this->payment_id,
            'confirms' => $this->confirms,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
