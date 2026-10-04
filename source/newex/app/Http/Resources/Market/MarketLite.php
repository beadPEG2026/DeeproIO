<?php

namespace App\Http\Resources\Market;

use Illuminate\Http\Resources\Json\JsonResource;

class MarketLite extends JsonResource
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
            'name' => $this->name,
            'sanitized_name' => market_sanitize($this->name),
        ];
    }
}
