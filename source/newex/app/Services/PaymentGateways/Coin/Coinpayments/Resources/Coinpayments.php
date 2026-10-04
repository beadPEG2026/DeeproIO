<?php

namespace App\Services\PaymentGateways\Coin\Coinpayments\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class Coinpayments extends JsonResource
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
            'name' => $this->name,
            'symbol' => $this->symbol,
            'confirmations' => $this->confirmations,
            'status' => $this->status,
            'fee' => $this->fee,
        ];
    }
}
