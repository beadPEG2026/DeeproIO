<?php

// Plain Order Helper Functions

use App\Models\Order\Order;

const INITIAL_TRADE_MAKER_FEE = 0.1;
const INITIAL_TRADE_TAKER_FEE = 0.25;

const INITIAL_FUTURES_MAKER_FEE = 0.02;
const INITIAL_FUTURES_TAKER_FEE = 0.05;

const INITIAL_REFERRAL_FEE = 10;

const ORDER_STATUS_ACTIVE = 'active';
const ORDER_STATUS_FILLED = 'filled';
const ORDER_STATUS_PARTIALLY_FILLED = 'partially_filled';
const ORDER_STATUS_CANCELLED = 'cancelled';

/*
 * Check if order is limit
 */
if (!function_exists('order_is_limit')) {
    function order_is_limit($type)
    {
        return $type === Order::TYPE_LIMIT;
    }
}

/*
 * Check if buy order
 */
if (!function_exists('order_is_buy')) {
    function order_is_buy($type)
    {
        return $type === Order::SIDE_BUY;
    }
}

/*
 * Check if order is market
 */
if (!function_exists('order_is_market')) {
    function order_is_market($type)
    {
        return $type === Order::TYPE_MARKET;
    }
}

/*
 * Check if order is buy market
 */
if (!function_exists('order_is_buy_market')) {
    function order_is_buy_market($type, $side)
    {
        return $type === Order::TYPE_MARKET && $side === Order::SIDE_BUY;
    }
}

/*
 * Check if order is sell market
 */
if (!function_exists('order_is_sell_market')) {
    function order_is_sell_market($type, $side)
    {
        return $type === Order::TYPE_MARKET && $side === Order::SIDE_SELL;
    }
}

/*
 * Check if order type is supported by exchange
 */
if (!function_exists('order_allowed_types')) {
    function order_allowed_types($type)
    {
        return $type === Order::TYPE_MARKET
            || $type === Order::TYPE_LIMIT
            || $type === Order::TYPE_STOP_LIMIT;
    }
}

/*
 * Check if order type is stop limit
 */
if (!function_exists('order_is_stop_limit')) {
    function order_is_stop_limit($type)
    {
        return $type === Order::TYPE_STOP_LIMIT;
    }
}

/*
 * Check if order type is stop limit
 */
if (!function_exists('order_limit_should_be_processed')) {
    function order_limit_should_be_processed($order, $market, $price, $condition)
    {
        if (!is_numeric($price) || math_compare($price, '0') <= 0 || !in_array($condition, [Order::STOP_LIMIT_CONDITION_DOWN, Order::STOP_LIMIT_CONDITION_UP], true)) return false;
        $market_price = market_get_stats($market, 'last');
        if (!is_numeric($market_price) || math_compare($market_price, '0') <= 0) return false;
        $shouldBeProcessed = $condition === Order::STOP_LIMIT_CONDITION_DOWN
            ? math_compare($market_price, $price) <= 0 : math_compare($market_price, $price) >= 0;
        if (!$shouldBeProcessed) return false;
        if (is_string($order)) $order = Order::find($order);
        if (!$order || (int) $order->market_id !== (int) $market) return false;
        $model = $order->market;
        if (!$model || !$model->status || !$model->trade_status || !($order->side === 'buy' ? $model->buy_order_status : $model->sell_order_status) || \Setting::get('trade.disable_trades', false)) return false;
        if (app(\App\Services\Market\HongKongPriceProduct::class)->tradingReason($model)) return false;
        // Conditional SQL transition is safe under concurrent workers and keeps the model guarded.
        $changed = Order::whereKey($order->id)->where('type', Order::TYPE_STOP_LIMIT)->update(['type' => Order::TYPE_LIMIT]);
        if (!$changed) return false;
        $order->type = Order::TYPE_LIMIT;
        return true;
    }
}

/*
 * Futures Liquidation Price Calculation
 */
if (!function_exists('liquidation_price_calculate')) {
    function liquidation_price_calculate($price, $leverage, $isLong = true)
    {
        $maxLeveragePrice = math_multiply($price, $leverage);

        if($isLong) {
            $leverageRatio = math_sum($leverage, 1);
        } else {
            $leverageRatio = math_sub($leverage, 1);
        }

        $leverageMarginRate = math_multiply(0.005, $leverage);

        if($isLong) {
            $ratioFormula = math_sub($leverageRatio, $leverageMarginRate);
        } else {
            $ratioFormula = math_sum($leverageRatio, $leverageMarginRate);
        }

        return math_formatter(math_divide($maxLeveragePrice, $ratioFormula), 8);
    }
}

/*
 * Futures PNL Calculation (Linear USDT-margined contract)
 * 
 * For linear contracts (USDT-margined):
 * - PNL (in quote currency) = Position Size * (Exit Price - Entry Price) for long
 * - PNL (in quote currency) = Position Size * (Entry Price - Exit Price) for short
 * - Margin = Position Size * Entry Price / Leverage
 * - PNL% = (PNL / Margin) * 100
 * 
 * @param string $quantity Position size in base currency (e.g., BTC)
 * @param string $entryPrice Entry price in quote currency
 * @param string $marketPrice Current market price in quote currency
 * @param int $leverage Leverage multiplier
 * @param bool $isLong True for long position, false for short
 * @return float PNL percentage
 */
if (!function_exists('futures_pnl_calculate')) {
    function futures_pnl_calculate($quantity, $entryPrice, $marketPrice, $leverage, $isLong)
    {
        if($marketPrice == 0 || !$marketPrice || $entryPrice == 0 || !$entryPrice) return 0;
        if($quantity == 0 || !$quantity) return 0;

        // Calculate PNL in quote currency (e.g., USDT)
        // Linear contract formula: PNL = quantity * (marketPrice - entryPrice)
        if($isLong) {
            // Long position profits when price goes up
            $PNL = math_multiply($quantity, math_sub($marketPrice, $entryPrice));
        } else {
            // Short position profits when price goes down
            $PNL = math_multiply($quantity, math_sub($entryPrice, $marketPrice));
        }

        // Calculate margin (initial collateral)
        // Margin = (Position Size * Entry Price) / Leverage
        // This is the initial USDT collateral required for the position
        $positionValue = math_multiply($quantity, $entryPrice);
        $margin = math_divide($positionValue, $leverage);

        // Avoid division by zero
        if($margin == 0 || !$margin) return 0;

        // Calculate PNL percentage
        $percentage = math_formatter(math_multiply(math_divide($PNL, $margin), 100), 5);

        // Cap losses at -100% (full margin loss)
        if($percentage < -100) {
            return -100;
        }

        return $percentage;
    }
}

/*
 * Futures Funding Fee Calculation (Perpetual Futures Standard)
 * 
 * Standard formula for perpetual futures:
 * - Funding Fee = Position Value × Funding Rate
 * - Position Value = Position Size (quantity) × Mark Price
 * 
 * The funding rate is expressed as a percentage (e.g., 0.01 = 0.01%)
 * Funding fees are paid/received every funding interval (typically 8 hours)
 */
if (!function_exists('futures_funding_fee_calculate')) {
    /**
     * Calculate funding fee for a futures position
     * 
     * @param string $positionValue Position value in quote currency (quantity * price)
     * @param string $fundingRate Funding rate as percentage (e.g., 0.01 for 0.01%)
     * @param bool $isLong Whether position is long (true) or short (false)
     * @return string Funding fee amount in quote currency
     */
    function futures_funding_fee_calculate($positionValue, $fundingRate, $isLong)
    {
        // Convert percentage to decimal (0.01% = 0.0001)
        $rateDecimal = math_divide($fundingRate, '100');
        
        // Funding fee = position_value * funding_rate
        // Long positions pay the fee (when rate is positive), short positions receive it
        // The sign will be handled when applying to the position
        $fee = math_multiply($positionValue, $rateDecimal);
        
        return $fee;
    }
}
