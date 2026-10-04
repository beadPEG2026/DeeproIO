<?php

namespace App\Http\Resources\Market\Cmc;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionCmc extends JsonResource
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
            'quote_volume' => $this->base_currency,
            'type' => $this->order_side,
            'timestamp' => $this->created_at->getTimestampMs(),
        ];
    }
}
