<?php

namespace App\Http\Resources\Market;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;
use Setting;

class Market extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $changeCached = market_get_stats($this->id, 'change');
        $last = market_get_stats($this->id, 'last');
        $hasPrice = is_numeric($last) && (float)$last > 0;
        $price = fn($key) => $hasPrice && (float)market_get_stats($this->id, $key) > 0 ? math_formatter(market_get_stats($this->id, $key), $this->quote_precision) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'stock_token' => \App\Services\Market\StockAssets::supports($this->name),
            'price_reference_product' => \App\Services\Market\HongKongPriceProduct::isCurrency($this->baseCurrency),
            'trading_session' => app(\App\Services\Market\HongKongPriceProduct::class)->marketSession($this->resource),
            's' => $this->liq,
            'sanitized_name' => market_sanitize($this->name),
            'base_currency' => $this->baseCurrency->symbol,
            'base_currency_name' => $this->baseCurrency->name,
            'base_currency_type' => $this->baseCurrency->type,
            'base_currency_logo' => url($this->baseCurrency->logo_path),
            'base_currency_discord' => $this->baseCurrency->social_discord,
            'base_currency_twitter' => $this->baseCurrency->social_twitter,
            'base_currency_website' => $this->baseCurrency->social_website,
            'quote_currency' => $this->quoteCurrency->symbol,
            'quote_currency_name' => $this->quoteCurrency->name,
            'quote_currency_type' => $this->quoteCurrency->type,
            'base_precision' => $this->base_precision,
            'quote_precision' => $this->quote_precision,
            'min_trade_size' => $this->min_trade_size,
            'max_trade_size' => $this->max_trade_size,
            'min_trade_value' => $this->min_trade_value,
            'max_trade_value' => $this->max_trade_value,
            'base_ticker_size' => $this->base_ticker_size,
            'quote_ticker_size' => $this->quote_ticker_size,
            'status' => $this->status,
            'ratio' => $this->discount,
            'ratio_b' => $this->discount_bid,
            'has_futures' => $this->has_futures,
            'has_options' => $this->has_options,
            'trade_status' => $this->trade_status,
            'buy_order_status' => $this->buy_order_status,
            'sell_order_status' => $this->sell_order_status,
            'cancel_order_status' => $this->cancel_order_status,
            'chart_enabled' => $this->is_tradingview,
            'listed' => Carbon::parse($this->created_at)->unix(),
            'custom_market_path' => $this->custom_market_path,
            'last' => $price('last'),
            'change' => $hasPrice && $changeCached !== null ? math_formatter($changeCached, 2) : null,
            'high' => $price('high'),
            'low' => $price('low'),
            'volume' => math_formatter(market_get_stats($this->id, 'volume'), $this->base_precision),
            'qVolume' => math_formatter(market_get_stats($this->id, 'qVolume'), $this->quote_precision),
            ...app(\App\Services\Market\TickerFreshness::class)->snapshot((int)$this->id),
            "fee" => Setting::get('trade.taker_fee', INITIAL_TRADE_TAKER_FEE),
            'opt_min' => $this->options_min_amount,
            'opt_max' => $this->options_max_amount,
            'is_meme' => $this->is_meme,
            'is_layer_one' => $this->is_layer_one,
            'is_layer_two' => $this->is_layer_two,
            'is_innovation' => $this->is_innovation,
            'is_ai' => $this->is_ai,
            'is_defi' => $this->is_defi,
            'is_gamefi' => $this->is_gamefi,
            'is_pow' => $this->is_pow,
            'is_fan_tokens' => $this->is_fan_tokens,
            'is_nft' => $this->is_nft,
            'listed_at' => $this->created_at->format('Ymdhis'),
        ];
    }
}
