<?php

namespace App\Http\Resources\Transaction;

use Illuminate\Http\Resources\Json\JsonResource;

class Transaction extends JsonResource
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
            'side' => $this->order_side,
            'price' => $this->price,
            'quantity' => $this->base_currency,
            'created_at' => $this->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];
    }
}
