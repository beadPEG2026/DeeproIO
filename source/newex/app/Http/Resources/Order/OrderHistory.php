<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Transaction\Trades\Trade;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderHistory extends JsonResource
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
            'id' => $this->id,
            'market' => $this->market->name,
            'base_currency' => $this->market->baseCurrency->symbol,
            'quote_currency' => $this->market->quoteCurrency->symbol,
            'symbol' => $this->type,
            'type' => $this->type,
            'side' => $this->side,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'transactions' => Trade::collection($this->transactions),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
