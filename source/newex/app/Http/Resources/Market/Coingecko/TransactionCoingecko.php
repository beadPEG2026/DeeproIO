<?php

namespace App\Http\Resources\Market\Coingecko;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionCoingecko extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        self::withoutWrapping();

        return [
            'trade_id' => md5('myexchange_' . $this->id),
            'price' => $this->price,
            'base_volume' => $this->base_currency,
            'target_volume' => $this->quote_currency,
            'type' => $this->order_side,
            'trade_timestamp' => $this->created_at->getTimestampMs(),
        ];
    }
}
