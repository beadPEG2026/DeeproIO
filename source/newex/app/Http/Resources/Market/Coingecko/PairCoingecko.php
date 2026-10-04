<?php

namespace App\Http\Resources\Market\Coingecko;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class PairCoingecko extends JsonResource
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
            'id' => $this->id,
            'ticker_id' => $this->baseCurrency->symbol . '_' . $this->quoteCurrency->symbol,
            'base' => $this->baseCurrency->symbol,
            'target' => $this->quoteCurrency->symbol,
        ];
    }
}
