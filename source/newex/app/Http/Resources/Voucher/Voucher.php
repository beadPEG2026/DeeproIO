<?php

namespace App\Http\Resources\Voucher;

use Illuminate\Http\Resources\Json\JsonResource;

class Voucher extends JsonResource
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
            'redeemed_at' => $this->redeemed_at->format('Y-m-d H:i:s'),
            'code' => $this->code,
            'amount' => $this->amount,
            'currency' => $this->currency->symbol
        ];
    }
}
