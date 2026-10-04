<?php

namespace App\Http\Resources\Market\Cmc;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class MarketCmc extends JsonResource
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
            'trading_pairs' => $this->name,
            'base_currency' => $this->baseCurrency->symbol,
            'quote_currency' => $this->quoteCurrency->symbol,
            'last_price' => math_formatter(market_get_stats($this->id, 'last'), $this->quote_precision),
            'lowest_ask' => math_formatter(market_get_stats($this->id, 'ask'), $this->quote_precision),
            'highest_bid' => math_formatter(market_get_stats($this->id, 'bid'), $this->quote_precision),
            'base_volume' => math_formatter(market_get_stats($this->id, 'volume'), $this->base_precision),
            'quote_volume' => math_formatter(market_get_stats($this->id, 'volumeQuote'), $this->quote_precision),
            'price_change_percent_24h' => math_percentage_between(market_get_stats($this->id, 'last'), $this->last),
            'highest_price_24h' => math_formatter(market_get_stats($this->id, 'high'), $this->quote_precision),
            'lowest_price_24h' => math_formatter(market_get_stats($this->id, 'low'), $this->quote_precision),
        ];
    }
}
