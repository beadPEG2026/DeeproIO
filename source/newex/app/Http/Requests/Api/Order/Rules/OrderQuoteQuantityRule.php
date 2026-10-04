<?php

namespace App\Http\Requests\Api\Order\Rules;

use App\Repositories\Market\MarketRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderQuoteQuantityRule implements Rule
{
    const GLOBAL_TRADE_PRECISION = 8;

    /**
     * @var MarketRepository
     * @var WalletRepository
     * @var OrderRepository
     */
    private $marketRepository, $orderRepository, $walletRepository, $error, $errorExtra;

    public function __construct()
    {
        $this->marketRepository = new MarketRepository();
        $this->walletRepository = new WalletRepository();
        $this->orderRepository = new OrderRepository();
        $this->error = '';
        $this->errorExtra = '';
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  numeric $quantity
     * @return bool
     */
    public function passes($attribute, $quantity)
    {
        // Request variables
        $marketName = request()->get('market');
        $type = request()->get('type');
        $side = request()->get('side');

        if (!order_is_buy_market($type, $side)) {
            return true;
        }

        if (
            $quantity === null ||
            $quantity === '' ||
            !is_numeric($quantity) ||
            mb_strpos((string) $quantity, 'e') !== false ||
            mb_strpos((string) $quantity, 'E') !== false ||
            math_compare($quantity, 0) < 1
        ) {
            $this->error = "Invalid format of the price";
            return false;
        }

        // User id
        $userModel = Auth::user();

        if (!$userModel) {
            $this->error = 'Unauthorized';
            return false;
        }

        $userId = $userModel->id;

        // Get market by name
        $market = $this->marketRepository->get($marketName);

        if (!$market) {
            $this->error = 'Market not found';
            return false;
        }

        // Check if order quantity follows min market buy amount rule
        if ($market->min_market_buy_amount > 0 && math_compare($quantity, $market->min_market_buy_amount) < 0) {
            $this->errorExtra = ' ' . $market->min_market_buy_amount . ' ' . ($market->quoteCurrency->symbol ?? '');
            $this->error = "Minimum Market Buy amount is";
            return false;
        }

        // Check if order quantity follows min trade value rule
        if ($market->min_trade_value > 0 && math_compare($quantity, $market->min_trade_value) < 0) {
            $this->errorExtra = ' ' . $market->min_trade_value;
            $this->error = "Minimum allowed trade value is";
            return false;
        }

        // Check if order quantity follows max trade value rule
        if ($market->max_trade_value > 0 && math_compare($market->max_trade_value, $quantity) < 0) {
            $this->errorExtra = ' ' . $market->max_trade_value;
            $this->error = "Maximum allowed trade value is";
            return false;
        }

        /**
         * 精度与当前交易对的配置一致。
         * 使用 markets.quote_precision。
         */
        if (!$this->validateDecimalPrecision($quantity, (int)$market->quote_precision)) {
            $this->errorExtra = ' ' . (int)$market->quote_precision;
            $this->error = "Quote quantity exceeds maximum allowed decimal places:";
            return false;
        }

        // Invalidate if there is no order to fill quick order
        $matchedOrder = $this->orderRepository->getMatchedOrder($type, $side, $market->id, false);

        if (!$matchedOrder && !$this->isFuturesStoreRequest()) {
            $liquidityType = $side == "buy" ? 'asks' : 'bids';
            $liquidity = app(\App\Services\Market\FundedLiquidity::class)->levels($market, \App\Services\Order\SpotFunding::requestedDomain((int)request()->user()->id, $side === "buy" ? $market->quote_currency_id : $market->base_currency_id), (int)request()->user()->id)[$liquidityType];

            if ($liquidity && count($liquidity)) {
                //
            } else {
                $this->error = 'No Matched Order';
                    return false;
            }
        }

        /**
         * 合约下单余额验证：
         *
         * 必须和 OrderRepository::allocateFuturesMarginWithVirtualWallet 保持一致。
         * 1. 合约开仓永远不使用资金账户 balance_in_wallet / balance_in_virtual_wallet。
         * 2. 虚拟用户 users.is_xn = true 或存在 balance_in_virtual_trade 时：
         *    使用 balance_in_virtual_trade + 全部 active 理财订单折算后的可用金额。
         * 3. 普通用户：
         *    使用 balance_in_trade + 全部 active 理财订单折算后的可用金额。
         * 4. 理财订单不限制 currency_id，BTC / ETH / USDT 等都会折算成当前合约 quote 币种。
         */
        if ($this->isFuturesStoreRequest()) {
            $wallet = $this->walletRepository->getWalletByCurrency($userId, $market->quote_currency_id);

            $availableBalance = $this->getFuturesAvailableBalance(
                $userId,
                $market->quote_currency_id,
                $wallet ? $wallet->balance_in_trade : '0'
            );

            if (math_compare($availableBalance, $quantity) < 0) {
                $this->error = 'Insufficient balance';
                return false;
            }

            return true;
        }

        // Get user wallet and its balance
        $wallet = $this->walletRepository->getWalletByCurrency($userId, $market->quote_currency_id);

        if (!$wallet) {
            $this->error = 'Insufficient balance';
            return false;
        }

        $availableBalance = $wallet->{\App\Services\Wallet\SpotFunds::field($wallet)};

        if (math_compare($availableBalance, $quantity) < 0) {
            $this->error = 'Insufficient balance';
            return false;
        }

        return true;
    }

    private function isFuturesStoreRequest(): bool
    {
        /*
         * 不能只判断 route name === futures.store。
         * 市价合约开仓一般会带 leverage，所以也用 leverage 兜底识别。
         */
        if (request()->has('leverage')) {
            return true;
        }

        if (request()->has('timeframeSeconds') || request()->has('timeframe_seconds')) {
            return true;
        }

        if (request()->boolean('futures') || request()->boolean('future')) {
            return true;
        }

        if ((string) request()->get('trade_type') === 'futures') {
            return true;
        }

        if ((string) request()->get('order_type') === 'futures') {
            return true;
        }

        $route = request()->route();

        if ($route) {
            $routeName = (string) ($route->getName() ?? '');

            if ($routeName !== '' && str_contains($routeName, 'futures')) {
                return true;
            }

            $uri = method_exists($route, 'uri') ? (string) $route->uri() : '';

            if ($uri !== '' && str_contains($uri, 'futures')) {
                return true;
            }
        }

        return str_contains((string) request()->path(), 'futures');
    }

    private function getFuturesAvailableBalance($userId, $currencyId, $tradeBalance)
    {
        /*
         * 合约验证层余额规则要和 OrderRepository 保持一致：
         *
         * 1. 开仓不能使用资金账户 balance_in_wallet / balance_in_virtual_wallet。
         * 2. 虚拟用户 users.is_xn = true 或虚拟交易余额 > 0：
         *    balance_in_virtual_trade + 全部 active 理财折算金额。
         * 3. 普通用户：
         *    balance_in_trade + 全部 active 理财折算金额。
         * 4. 理财订单不限制 currency_id，会按 markets.last 折算成当前合约 quote 币种。
         */

        $virtualTrade = '0';

        if (Schema::hasColumn('wallets', 'balance_in_virtual_trade')) {
            $virtualTrade = DB::table('wallets')
                ->where('user_id', $userId)
                ->where('currency_id', $currencyId)
                ->selectRaw('COALESCE(SUM(balance_in_virtual_trade), 0) as total')
                ->value('total');

            $virtualTrade = $virtualTrade ?: '0';
        }

        $autoInvestAvailable = $this->getAutoInvestAvailableForFutures($userId, $currencyId);
        $isVirtualUser = $this->isVirtualUser($userId);

        if ($isVirtualUser || math_compare($virtualTrade, 0) > 0) {
            return math_sum($virtualTrade, $autoInvestAvailable);
        }

        $tradeBalance = $tradeBalance ?: '0';

        return math_sum($tradeBalance, $autoInvestAvailable);
    }

    private function getAutoInvestAvailableForFutures($userId, int $quoteCurrencyId): string
    {
        if (!Schema::hasTable('auto_invest_orders')) {
            return '0';
        }

        $hasUsedMargin = Schema::hasColumn('auto_invest_orders', 'used_margin');

        $selectColumns = [
            'id',
            'currency_id',
            'amount',
        ];

        if ($hasUsedMargin) {
            $selectColumns[] = 'used_margin';
        }

        $query = DB::table('auto_invest_orders')
            ->select($selectColumns)
            ->where('user_id', $userId)
            ->where('status', 'active');

        if ($hasUsedMargin) {
            $query->whereRaw('(COALESCE(amount, 0) - COALESCE(used_margin, 0)) > 0');
        } else {
            $query->whereRaw('COALESCE(amount, 0) > 0');
        }

        $orders = $query->get();

        $totalQuoteAmount = '0';

        foreach ($orders as $order) {
            $orderCurrencyId = (int) ($order->currency_id ?? 0);

            if ($orderCurrencyId <= 0) {
                continue;
            }

            $availableAmount = $hasUsedMargin
                ? math_sub($order->amount ?? 0, $order->used_margin ?? 0)
                : ($order->amount ?? 0);

            if (math_compare($availableAmount, 0) <= 0) {
                continue;
            }

            $quoteAmount = $this->convertAutoInvestAmountToQuoteCurrency(
                $availableAmount,
                $orderCurrencyId,
                $quoteCurrencyId
            );

            if (math_compare($quoteAmount, 0) <= 0) {
                continue;
            }

            $totalQuoteAmount = math_sum($totalQuoteAmount, $quoteAmount);
        }

        return math_formatter($totalQuoteAmount, 8, '.', '');
    }

    private function convertAutoInvestAmountToQuoteCurrency($amount, int $fromCurrencyId, int $toCurrencyId): string
    {
        if (math_compare($amount, 0) <= 0) {
            return '0';
        }

        if ($fromCurrencyId === $toCurrencyId) {
            return math_formatter($amount, 8, '.', '');
        }

        $fromRate = $this->getCurrencyToUsdtRate($fromCurrencyId);
        $toRate = $this->getCurrencyToUsdtRate($toCurrencyId);

        if (math_compare($fromRate, 0) <= 0 || math_compare($toRate, 0) <= 0) {
            return '0';
        }

        return math_formatter(
            math_divide(math_multiply($amount, $fromRate), $toRate),
            8,
            '.',
            ''
        );
    }

    private function getCurrencyToUsdtRate(int $currencyId): string
    {
        if ($currencyId <= 0 || !Schema::hasTable('currencies')) {
            return '0';
        }

        $symbol = DB::table('currencies')
            ->where('id', $currencyId)
            ->value('symbol');

        $symbol = strtoupper(trim((string) $symbol));

        if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
            return '1';
        }

        if (!Schema::hasTable('markets')) {
            return '0';
        }

        /*
         * 正向交易对：BTC-USDT，last 表示 1 BTC = ? USDT。
         */
        $directRate = DB::table('markets as m')
            ->join('currencies as q', 'q.id', '=', 'm.quote_currency_id')
            ->where('m.base_currency_id', $currencyId)
            ->whereIn(DB::raw('UPPER(q.symbol)'), ['USDT', 'USDC', 'USD'])
            ->whereRaw('COALESCE(m.last, 0) > 0')
            ->orderByRaw("CASE WHEN UPPER(q.symbol) = 'USDT' THEN 0 WHEN UPPER(q.symbol) = 'USDC' THEN 1 ELSE 2 END")
            ->value('m.last');

        if (is_numeric($directRate) && math_compare($directRate, 0) > 0) {
            return math_formatter($directRate, 18, '.', '');
        }

        /*
         * 反向交易对：USDT-BTC，last 表示 1 USDT = ? BTC。
         * 需要取倒数换算 1 BTC = ? USDT。
         */
        $inverseRate = DB::table('markets as m')
            ->join('currencies as b', 'b.id', '=', 'm.base_currency_id')
            ->where('m.quote_currency_id', $currencyId)
            ->whereIn(DB::raw('UPPER(b.symbol)'), ['USDT', 'USDC', 'USD'])
            ->whereRaw('COALESCE(m.last, 0) > 0')
            ->orderByRaw("CASE WHEN UPPER(b.symbol) = 'USDT' THEN 0 WHEN UPPER(b.symbol) = 'USDC' THEN 1 ELSE 2 END")
            ->value('m.last');

        if (is_numeric($inverseRate) && math_compare($inverseRate, 0) > 0) {
            return math_formatter(math_divide('1', $inverseRate), 18, '.', '');
        }

        return '0';
    }

    private function isVirtualUser($userId): bool
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'is_xn')) {
            return false;
        }

        return (bool) DB::table('users')
            ->where('id', $userId)
            ->value('is_xn');
    }

    /**
     * 校验小数位。
     * 不调用 math_decimal_validation，避免继续读取 markets 精度。
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
        return __($this->error) . $this->errorExtra;
    }
}
