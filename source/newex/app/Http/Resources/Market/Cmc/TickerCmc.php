<?php

namespace App\Http\Resources\Market\Cmc;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class TickerCmc extends JsonResource
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

        return [trim($this->name) =>
            [
                'base_id' => $this->baseCurrency->id,
                'quote_id' => $this->quoteCurrency->id,
                'last_price' => math_formatter(market_get_stats($this->id, 'last'), $this->quote_precision),
                'base_volume' => math_formatter(market_get_stats($this->id, 'volume'), $this->base_precision),
                'quote_volume' => math_formatter(market_get_stats($this->id, 'volumeQuote'), $this->quote_precision),
                'isFrozen' => ($this->status && $this->trade_status) ? 0 : 1,
            ]
        ];
    }
}
