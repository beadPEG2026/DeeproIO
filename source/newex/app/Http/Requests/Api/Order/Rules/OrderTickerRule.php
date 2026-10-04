<?php

namespace App\Http\Requests\Api\Order\Rules;

use Illuminate\Contracts\Validation\Rule;

class OrderTickerRule implements Rule
{
    /**
     * 全站统一 8 位小数。
     * 不再依赖 markets.base_ticker_size / markets.quote_ticker_size。
     */
    const GLOBAL_TRADE_PRECISION = 8;
    const GLOBAL_TICK_SIZE = '0.00000001';

    private $isBuyMarket;
    private $tickSize;

    public function __construct($isBuyMarket = false)
    {
        $this->isBuyMarket = $isBuyMarket;
        $this->tickSize = self::GLOBAL_TICK_SIZE;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        /**
         * 市价买入时，真实使用 quoteQuantity。
         * quantity 不参与 tick size 校验。
         */
        if ($this->isBuyMarket && $attribute != 'quoteQuantity') {
            return true;
        }

        /**
         * 市价卖出时，price 不参与 tick size 校验。
         */
        $isSellMarket = order_is_sell_market(request()->get('type'), request()->get('side'));

        if ($isSellMarket && $attribute == 'price') {
            return true;
        }

        /**
         * 非必填字段为空时交给 required / nullable 自己处理。
         */
        if ($value === null || $value === '') {
            return true;
        }

        if (
            !is_numeric($value) ||
            mb_strpos((string) $value, 'e') !== false ||
            mb_strpos((string) $value, 'E') !== false ||
            math_compare($value, 0) < 1
        ) {
            return false;
        }

        /**
         * 最小交易精度统一为 0.00000001。
         */
        if (math_compare($value, self::GLOBAL_TICK_SIZE) < 0) {
            return false;
        }

        /**
         * 最多允许 8 位小数。
         */
        if (!$this->validateDecimalPrecision($value, self::GLOBAL_TRADE_PRECISION)) {
            return false;
        }

        /**
         * 校验是否符合 0.00000001 的 tick size。
         * 8 位小数以内的正常数字都会通过。
         */
        $fraction = math_divide($value, self::GLOBAL_TICK_SIZE, MATH_SCALE_REMAINDER);

        return $fraction == intval($fraction);
    }

    /**
     * 校验小数位。
     *
     * @param mixed $value
     * @param int $precision
     * @return bool
     */
    private function validateDecimalPrecision($value, int $precision): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        if (mb_strpos($value, 'e') !== false || mb_strpos($value, 'E') !== false) {
            return false;
        }

        if (!is_numeric($value)) {
            return false;
        }

        if (strpos($value, '.') === false) {
            return true;
        }

        $parts = explode('.', $value, 2);
        $decimalPart = $parts[1] ?? '';

        return strlen($decimalPart) <= $precision;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('Minimum allowed tick size is') . ' ' . $this->tickSize;
    }
}