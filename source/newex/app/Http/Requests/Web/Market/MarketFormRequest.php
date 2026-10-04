<?php

namespace App\Http\Requests\Web\Market;

use App\Http\Requests\Web\Market\Rules\MarketStoreRule;
use Illuminate\Foundation\Http\FormRequest;

class MarketFormRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function isUpdateRequest(): bool
    {
        $methodOverride = strtolower((string) $this->input('_method', ''));

        return $this->isMethod('put')
            || $this->isMethod('patch')
            || in_array($methodOverride, ['put', 'patch'], true)
            || $this->route('market')
            || $this->route('MarketAdmin')
            || $this->route('marketAdmin');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $existing=$this->route('market');
            if ($existing instanceof \App\Models\Market\Market && \App\Services\Market\HongKongPriceProduct::isMarket($existing)) {
                foreach (['name','base_currency_id','quote_currency_id'] as $field)
                    if ($this->has($field) && (string)$this->input($field)!==(string)$existing->$field) $validator->errors()->add($field,__('Verified asset identity cannot be changed here'));
            }
            $base=\App\Models\Currency\Currency::find($this->input('base_currency_id'));
            if ($base && \App\Services\Market\HongKongPriceProduct::isCurrency($base)) {
                foreach (['has_futures','has_options','custom_liquidity','custom_liquidity_t'] as $field)
                    if ($this->boolean($field)) $validator->errors()->add($field,__('Price reference products use local user orders only'));
                if ($this->input('chart_source') !== 'hk-reference') $validator->errors()->add('chart_source',__('Verified asset identity cannot be changed here'));
            }
            if (config('admin-controls.simulation_controls')) return;
            $market = $this->route('market');
            foreach (['custom_liquidity','custom_liquidity_t','custom_liquidity_start_price','custom_liquidity_end_price','bot_price_floor','bot_price_ceiling'] as $key) {
                if (!$this->has($key)) continue;
                $value = $this->input($key);
                $old = is_object($market) ? $market->{$key} : null;
                if ((float) $value !== (float) $old) $validator->errors()->add($key, __('Simulation controls are disabled in production.'));
            }
        });
    }

    public function rules()
    {
        /*
         * 新增市场：last 必填
         * 编辑市场：last 不必填
         *
         * 因为编辑时前端会删除 last，不让后台保存覆盖实时行情价格。
         */
        $lastRules = $this->isUpdateRequest()
            ? ['sometimes', 'nullable', 'numeric', 'min:0.0000000001', 'max:900000000000000000']
            : ['required', 'numeric', 'min:0.0000000001', 'max:900000000000000000'];

        return [
            'id' => ['sometimes', 'required', 'exists:markets,id'],
            'name' => ['required', 'max:30', 'min:3'],

            'base_currency_id' => ['bail', 'required', 'numeric', 'exists:currencies,id'],
            'quote_currency_id' => ['bail', 'required', 'numeric', 'exists:currencies,id', new MarketStoreRule()],

            'base_precision' => ['required', 'integer', 'min:0', 'max:18'],
            'quote_precision' => ['required', 'integer', 'min:0', 'max:18'],

            'min_trade_size' => ['required', 'numeric', 'min:0.000000000001', 'max:900000000000000000'],
            'max_trade_size' => ['required', 'numeric', 'gte:min_trade_size', 'min:0.000000000001', 'max:900000000000000000'],
            'min_trade_value' => ['required', 'numeric', 'min:0.000000000001', 'max:900000000000000000'],
            'max_trade_value' => ['required', 'numeric', 'gte:min_trade_value', 'min:0.000000000001', 'max:900000000000000000'],

            'min_market_buy_amount' => ['nullable', 'numeric', 'min:0', 'max:900000000000000000'],

            'base_ticker_size' => ['required', 'numeric', 'min:0.000000000001', 'max:900000000000000000'],
            'quote_ticker_size' => ['required', 'numeric', 'min:0.000000000001', 'max:900000000000000000'],

            'status' => ['required'],
            'trade_status' => ['required', 'boolean'],
            'buy_order_status' => ['required', 'boolean'],
            'sell_order_status' => ['required', 'boolean'],
            'cancel_order_status' => ['required', 'boolean'],

            'is_tradingview' => ['nullable', 'string', 'max:255'],
            'custom_market_path' => ['nullable', 'max:255'],

            'switch_chart' => ['sometimes', 'boolean'],
            'chart_source' => ['nullable', 'string', 'in:binance,mexc,bybit,ondo-reference,hk-reference,internal'],
            'chart_symbol' => ['nullable', 'string', 'max:50'],
            'chart_default_resolution' => ['nullable', 'string', 'max:20'],

            'last' => $lastRules,

            'discount' => ['required', 'numeric', 'min:0', 'max:100'],
            'discount_bid' => ['required', 'numeric', 'min:0', 'max:100'],

            'has_futures' => ['sometimes', 'boolean'],
            'has_options' => ['sometimes', 'boolean'],

            'options_min_amount' => ['nullable', 'numeric', 'min:0'],
            'options_max_amount' => ['nullable', 'numeric', 'min:0'],

            'is_meme' => ['sometimes', 'boolean'],
            'is_layer_one' => ['sometimes', 'boolean'],
            'is_layer_two' => ['sometimes', 'boolean'],
            'is_innovation' => ['sometimes', 'boolean'],
            'is_ai' => ['sometimes', 'boolean'],
            'is_defi' => ['sometimes', 'boolean'],
            'is_gamefi' => ['sometimes', 'boolean'],
            'is_pow' => ['sometimes', 'boolean'],
            'is_fan_tokens' => ['sometimes', 'boolean'],
            'is_nft' => ['sometimes', 'boolean'],

            'custom_liquidity' => ['sometimes', 'boolean'],
            'custom_liquidity_t' => ['nullable', 'boolean'],

            'custom_liquidity_start_price' => ['nullable', 'numeric'],
            'custom_liquidity_end_price' => ['nullable', 'numeric'],
            'custom_liquidity_start_amount' => ['nullable', 'numeric'],
            'custom_liquidity_end_amount' => ['nullable', 'numeric'],
            'custom_liquidity_start_time' => ['nullable', 'string', 'max:255'],
            'custom_liquidity_stop_time' => ['nullable', 'string', 'max:255'],

            'bot_trend_direction' => ['nullable', 'string', 'max:255'],
            'bot_trend_strength' => ['nullable', 'numeric'],
            'bot_volatility' => ['nullable', 'numeric'],
            'bot_volatility_burst_chance' => ['nullable', 'numeric'],
            'bot_price_floor' => ['nullable', 'numeric'],
            'bot_price_ceiling' => ['nullable', 'numeric'],
            'bot_orderbook_depth' => ['nullable', 'integer', 'min:1'],
            'bot_spread_percentage' => ['nullable', 'numeric'],
            'bot_trade_frequency' => ['nullable', 'integer', 'min:1'],
            'bot_cycle_interval' => ['nullable', 'integer', 'min:1'],
            'bot_cycle_interval_min' => ['nullable', 'integer', 'min:1'],
            'bot_cycle_interval_max' => ['nullable', 'integer', 'min:1'],
            'bot_current_price' => ['nullable', 'numeric'],
            'bot_momentum' => ['nullable', 'numeric'],

            'sc_price_floor' => ['nullable', 'numeric'],
            'sc_price_ceiling' => ['nullable', 'numeric'],
            'sc_custom_liquidity_start_time' => ['nullable', 'string', 'max:255'],
            'sc_custom_liquidity_stop_time' => ['nullable', 'string', 'max:255'],
            'sc_bot_trend_direction' => ['nullable', 'string', 'max:255'],

            'yctime' => ['nullable'],
            'bs' => ['nullable', 'numeric'],
            'change_percent_24h' => ['nullable', 'numeric'],
        ];
    }
}