<?php

namespace App\Models\Market;

use App\Casts\CryptoCurrencyDecimalCast;
use App\Models\Market\Traits\Relations\MarketRelation;
use App\Models\Market\Traits\Scopes\MarketScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Market extends Model
{
    use HasFactory, SoftDeletes, MarketRelation, MarketScope;

    public $fillable = [
        'name',
        'base_currency_id',
        'quote_currency_id',
        'base_precision',
        'quote_precision',
        'min_trade_size',
        'max_trade_size',
        'min_trade_value',
        'max_trade_value',
        'min_market_buy_amount',
        'base_ticker_size',
        'quote_ticker_size',
        'liq',
        'status',
        'switch_chart',
        'chart_source',
        'chart_symbol',
        'chart_default_resolution',
        'trade_status',
        'buy_order_status',
        'sell_order_status',
        'cancel_order_status',
        'is_tradingview',
        'custom_market_path',
        'last',
        'has_futures',
        'has_options',
        'discount',
        'discount_bid',
        'bs',
        'options_min_amount',
        'options_max_amount',
        'is_trending',
        'is_meme',
        'is_layer_one',
        'is_layer_two',
        'is_innovation',
        'is_ai',
        'is_defi',
        'is_gamefi',
        'is_pow',
        'is_fan_tokens',
        'is_nft',
        'custom_liquidity',
        'custom_liquidity_t',
        'custom_liquidity_start_price',
        'custom_liquidity_end_price',
        'custom_liquidity_start_amount',
        'custom_liquidity_end_amount',
        // Trading Bot Settings
        'bot_trend_direction',
        'bot_trend_strength',
        'bot_volatility',
        'bot_volatility_burst_chance',
        'bot_price_floor',
        'bot_price_ceiling',
        'bot_orderbook_depth',
        'bot_spread_percentage',
        'bot_trade_frequency',
        'bot_cycle_interval',
        'bot_cycle_interval_min',
        'bot_cycle_interval_max',
        'bot_current_price',
        'bot_momentum',
        'custom_liquidity_start_time',
        'yctime',
        'custom_liquidity_stop_time'
    ];

    protected $casts = [
        'status' => 'boolean',
        'trade_status' => 'boolean',
        'buy_order_status' => 'boolean',
        'sell_order_status' => 'boolean',
        'has_futures' => 'boolean',
        'has_options' => 'boolean',
        'cancel_order_status' => 'boolean',
        'custom_liquidity' => 'boolean',
        'switch_chart' => 'boolean',
        'min_trade_size' => CryptoCurrencyDecimalCast::class,
        'max_trade_size' => CryptoCurrencyDecimalCast::class,
        'min_trade_value' => CryptoCurrencyDecimalCast::class,
        'max_trade_value' => CryptoCurrencyDecimalCast::class,
        'min_market_buy_amount' => CryptoCurrencyDecimalCast::class,
        'base_ticker_size' => CryptoCurrencyDecimalCast::class,
        'quote_ticker_size' => CryptoCurrencyDecimalCast::class,
        'bid' => CryptoCurrencyDecimalCast::class,
        'ask' => CryptoCurrencyDecimalCast::class,
        'last' => CryptoCurrencyDecimalCast::class,
        'high' => CryptoCurrencyDecimalCast::class,
        'low' => CryptoCurrencyDecimalCast::class,
        'volume' => CryptoCurrencyDecimalCast::class,
        'capitalization' => CryptoCurrencyDecimalCast::class,
        'change_amount' => CryptoCurrencyDecimalCast::class,
        // Trading Bot Casts
        'bot_trend_strength' => 'float',
        'bot_volatility' => 'float',
        'bot_volatility_burst_chance' => 'float',
        'bot_orderbook_depth' => 'integer',
        'bot_spread_percentage' => 'float',
        'bot_trade_frequency' => 'integer',
        'bot_cycle_interval' => 'integer',
        'bot_cycle_interval_min' => 'integer',
        'bot_cycle_interval_max' => 'integer',
        'bot_momentum' => 'float',
    ];
}
