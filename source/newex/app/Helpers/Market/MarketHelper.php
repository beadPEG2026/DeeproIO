<?php

// Constants
const MARKET_CHART_RESOLUTION = ['1', '5', '15', '30', '60', '240', '720', '1D', '3D', '1W', '1M'];
const MARKET_CHART_TYPE = 'bitcoin';
const MARKET_CHART_CONFIGS = [
    'supports_marks' => false,
    'supports_search' => true,
    'supports_time' => true,
    'supports_timescale_marks' => false,
    'supported_resolutions' => MARKET_CHART_RESOLUTION
];

const MARKET_RESOLUTION_ASSOC = [
    '1' => '60',
    '5' => '300',
    '15' => '900',
    '30' => '1800',
    '60' => '3600',
    '240' => '14400',
    '720' => '43200',
    '1D' => '86400',
    '1W' => '604800',
    '1M' => '2592000',
];

// Plain Math PHP Functions

/*
 * Set stats to cache
 */
if (!function_exists('market_set_stats')) {
    function market_set_stats($market_id, $type, $value)
    {
        $cacheKey = 'market.' . $market_id . '.' . $type;

        $marketCache = cache()->get($cacheKey);

        if(!$marketCache) {
            return cache()->set($cacheKey, $value);
        }

        // Set ask
        if($type == "ask") {
            return cache()->set($cacheKey, $value);
        }

        // Set bid
        if($type == "bid") {
            return cache()->set($cacheKey, $value);
        }

        // Set last market price
        if($type == "last") {
            return cache()->set($cacheKey, $value);
        }

        // Set market 24h high
        if($type == "high" && $value > $marketCache) {
            return cache()->set($cacheKey, $value);
        }

        // Set market 24h low
        if($marketCache == 0 || ($type == "low" && $value < $marketCache)) {
            return cache()->set($cacheKey, $value);
        }

        // Set market 24h volume
        if($type == "volume") {
            return cache()->set($cacheKey, cache()->get($cacheKey) + $value);
        }

        if($type == "volumeQuote") {
            return cache()->set($cacheKey, cache()->get($cacheKey) + $value);
        }

        // Set market 24h capitalization
        if($type == "capitalization") {
            return cache()->set($cacheKey, $value);
        }
    }
}

/*
 * Set stats to cache force
 */
if (!function_exists('market_set_stats_force')) {
    function market_set_stats_force($market_id, $type, $value)
    {
        $cacheKey = 'market.' . $market_id . '.' . $type;
        return cache()->set($cacheKey, $value);
    }
}

/*
 * Get stats from cache
 */
if (!function_exists('market_get_stats')) {
    function market_get_stats($market_id, $type)
    {
        $cacheKey = 'market.' . $market_id . '.' . $type;

        return cache()->get($cacheKey) ?? ($type === 'change' ? null : 0.00);
    }
}

/*
 * Is Market Tradable
 */
if (!function_exists('market_is_tradable')) {
    function market_is_tradable($market)
    {
        return $market->trade_status;
    }
}

/*
 * Is Market Tradable with buy orders
 */
if (!function_exists('market_is_buy_orders_allowed')) {
    function market_is_buy_orders_allowed($market)
    {
        return $market->buy_order_status;
    }
}

/*
 * Is Market Tradable with sell orders
 */
if (!function_exists('market_is_sell_orders_allowed')) {
    function market_is_sell_orders_allowed($market)
    {
        return $market->sell_order_status;
    }
}

/*
 * Is Market Cancel order allowed
 */
if (!function_exists('market_is_cancel_orders_allowed')) {
    function market_is_cancel_orders_allowed($market)
    {
        if(!$market) return false;

        return $market->cancel_order_status;
    }
}

/*
 * Is Min Trade Size Followed
 */
if (!function_exists('market_is_order_follow_trade_min_size')) {
    function market_is_order_follow_trade_min_size($market, $quantity)
    {
        if($market->min_trade_size == 0) return true;

        if($quantity >= $market->min_trade_size)

        return $market->cancel_order_status;
    }
}

/*
 * Sanitize Market Name
 */
if (!function_exists('market_sanitize')) {
    function market_sanitize($market)
    {
        $market = str_replace('-', '', $market);
        $market = str_replace('_', '', $market);
        $market = str_replace(' ', '', $market);
        return str_replace('/', '', $market);
    }
}

/*
 * Generate market volume
 */
if (!function_exists('generate_market_volume')) {
    function generate_market_volume($market)
    {
        $markets = config('app.rates');

        $exponent = math_formatter(math_divide(1, pow(10, $markets[$market]['digits'])), $markets[$market]['digits']);

        $randWord = ['often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','rare','often','often','often','often','often','often','often','medium','often','often','often','often','medium','often','often','often','often','often','often','often','medium','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','often','medium','often','often','often','often','often','often','often','often','often','often','often','often','medium','often'];

        $frequency = ($randWord[rand(0, count($randWord)-1)]);

        return math_formatter(math_multiply($exponent, rand($markets[$market][$frequency][0], $markets[$market][$frequency][1])), $markets[$market]['digits']);
    }
}


