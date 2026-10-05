<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Wallet\Gateways\Stripe\StripePaymentFormRequest;
use App\Http\Requests\Api\Wallet\GetAddressRequest;
use App\Http\Requests\Api\Wallet\WithdrawRequest;
use App\Http\Requests\Api\Wallet\TransferRequest;
use App\Http\Resources\Currency\Currency;
use App\Http\Resources\Wallet\Deposit\Deposit;
use App\Http\Resources\Wallet\Deposit\FiatDeposit;
use App\Http\Resources\Wallet\WalletCollection;
use App\Http\Resources\Wallet\Withdrawal\FiatWithdrawal;
use App\Http\Resources\Wallet\Withdrawal\Withdrawal;
use App\Models\Network\Network;
use App\Http\Resources\Network\NetworkLite;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\FiatWithdrawalRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use App\Services\Currency\CurrencyService;
use App\Services\PaymentGateways\Fiat\Stripe\Model\StripeModel;
use App\Services\Wallet\WalletService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use DB;
use Setting;
use Carbon\Carbon;
/**
 * @tags Wallet
 */
class WalletController extends Controller
{
    /**
     * @var walletService
     */
    protected $walletService;

    protected static $schemaTableExistsCache = [];

    protected static $schemaColumnExistsCache = [];

    protected static $walletOwnerVirtualCache = [];

    protected static $currencySymbolCache = [];

    protected static $usdtCurrencyIdsCache = null;

    protected static $usdQuoteCurrencyIdsCache = null;

    protected static $walletUsdMarketPriceMapCache = [];

    protected static $stakingProductCurrencyCache = [];

    protected static $activeAutoInvestOrdersCache = [];

    /**
     * @param WalletService $walletService
     *
     */
    public function __construct(WalletService $walletService)
    {    
        $this->walletService = $walletService;
    }

    private function hasTableCached(string $table): bool
    {
        if(!array_key_exists($table, static::$schemaTableExistsCache)) {
            $cacheKey = 'schema:table:' . config('database.default') . ':' . $table;

            try {
                static::$schemaTableExistsCache[$table] = (bool) Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->remember(
                    $cacheKey,
                    now()->addDay(),
                    function () use ($table) {
                        return \Illuminate\Support\Facades\Schema::hasTable($table);
                    }
                );
            } catch (\Throwable $e) {
                try {
                    static::$schemaTableExistsCache[$table] = \Illuminate\Support\Facades\Schema::hasTable($table);
                } catch (\Throwable $e) {
                    static::$schemaTableExistsCache[$table] = false;
                }
            }
        }

        return static::$schemaTableExistsCache[$table];
    }

    private function hasColumnCached(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if(!array_key_exists($key, static::$schemaColumnExistsCache)) {
            $cacheKey = 'schema:column:' . config('database.default') . ':' . $key;

            try {
                static::$schemaColumnExistsCache[$key] = (bool) Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->remember(
                    $cacheKey,
                    now()->addDay(),
                    function () use ($table, $column) {
                        return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
                    }
                );
            } catch (\Throwable $e) {
                try {
                    static::$schemaColumnExistsCache[$key] = \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
                } catch (\Throwable $e) {
                    static::$schemaColumnExistsCache[$key] = false;
                }
            }
        }

        return static::$schemaColumnExistsCache[$key];
    }

    /**
     * Get All Wallets
     *
     * Retrieves all wallets for the authenticated user.
     * Each wallet contains balance information for both funding and trading accounts.
     *
     * @operationId getAllWallets
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "currency_id": 1,
     *       "currency_symbol": "BTC",
     *       "currency_name": "Bitcoin",
     *       "balance_in_wallet": "1.50000000",
     *       "balance_in_trade": "0.50000000",
     *       "balance_in_order": "0.10000000",
     *       "total_balance": "2.10000000"
     *     },
     *     {
     *       "id": 2,
     *       "currency_id": 2,
     *       "currency_symbol": "USDT",
     *       "currency_name": "Tether",
     *       "balance_in_wallet": "10000.00",
     *       "balance_in_trade": "5000.00",
     *       "balance_in_order": "1000.00",
     *       "total_balance": "16000.00"
     *     }
     *   ]
     * }
     *
     * @return WalletCollection
     */
    public function index(Request $request)
    {
        $wallets = $this->walletService->getWallets();
        $userId = (int) $request->user()->id;
        $isFuturesContext = $request->get('context') === 'futures';
        $isVirtualUser = $this->isWalletOwnerVirtual($userId);

        /*
         * 只有合约页面才使用虚拟账户 USDT 归并展示。
         *
         * 规则：
         * 1. context=futures 且 users.is_xn = true 时才执行。
         * 2. USDT 原本的 balance_in_virtual_trade 保留。
         * 3. active 理财订单折算成 USDT 后，加到 USDT.balance_in_virtual_trade。
         * 4. 不影响钱包首页、资产页、充值提现等其他页面，它们继续正常展示原币种余额。
         * 5. 这里不再调用 appendAutoInvestMarginToTradeBalance，避免理财金额重复加入。
         */
        if ($isFuturesContext && $isVirtualUser) {
            $wallets = $this->normalizeVirtualUserWalletsToUsdt($wallets, $userId);
            $wallets = $this->appendRealAndVirtualBalancesToWallets($wallets);

            return new WalletCollection($wallets);
        }

        /*
         * 非合约页面：保持原来的钱包展示，不做 USDT 归并。
         *
         * 例如：
         * BTC 还是显示 BTC；OKR 还是显示 OKR；USDT 还是显示 USDT。
         */
        if ($isFuturesContext) {
            $wallets = $this->appendAutoInvestMarginToTradeBalance($wallets, $userId);
        }

        $wallets = $this->appendRealAndVirtualBalancesToWallets($wallets);
        $wallets = $this->appendWalletsUsdtSummary($wallets, $userId);
        $wallets = $this->appendActiveAutoInvestOrdersToLockedBalanceForDisplay($wallets, $userId);

        return new WalletCollection($wallets);
    }
    private function appendAutoInvestMarginToTradeBalance($wallets, int $userId)
    {
        if (!$this->hasTableCached('auto_invest_orders')) {
            return $wallets;
        }
    
        $currencyIds = $wallets->pluck('currency_id')->filter()->unique()->values();
    
        if ($currencyIds->isEmpty()) {
            return $wallets;
        }
    
        /**
         * 可用于合约开仓的理财金额：
         * active 订单 amount - used_margin
         *
         * amount 是当前理财订单金额，已经包含 0 点结算后的收益或亏损。
         * used_margin 是已经被合约占用的金额，不能重复参与新开仓。
         */
        $autoInvestMargins = $this->sumActiveAutoInvestOrdersByCurrency(
            $userId,
            $currencyIds->all(),
            true
        );
    
        foreach ($wallets as $wallet) {
            $availableAutoInvestMargin = (string) ($autoInvestMargins[$wallet->currency_id] ?? 0);
    
            /**
             * 单独返回字段，方便前端展示“理财保证金支持”
             */
            $wallet->setAttribute('available_auto_invest_margin', $availableAutoInvestMargin);
    
            /**
             * 合约页面展示/计算用：
             * balance_in_trade = 原交易余额 + 可用理财保证金
             *
             * 这里只改接口返回的数据，不会修改 wallets 表。
             */
            if (math_compare($availableAutoInvestMargin, 0) > 0) {
                $wallet->setAttribute(
                    'balance_in_trade',
                    math_sum($wallet->balance_in_trade, $availableAutoInvestMargin)
                );
            }
        }
    
        return $wallets;
    }

    private function appendActiveAutoInvestOrdersToLockedBalanceForDisplay($wallets, int $userId)
    {
        if (
            !$this->hasTableCached('auto_invest_orders') &&
            !$this->hasTableCached('staking_users')
        ) {
            return $wallets;
        }

        $currencyIds = $wallets->pluck('currency_id')->filter()->unique()->values();

        if ($currencyIds->isEmpty()) {
            return $wallets;
        }

        $activeAmounts = collect();

        if ($this->hasTableCached('auto_invest_orders')) {
            $activeAmounts = $this->sumActiveAutoInvestOrdersByCurrency(
                $userId,
                $currencyIds->all(),
                false
            );
        }

        $stakingAmounts = $this->getActiveStakingAmountsByCurrency($userId, $currencyIds->all());

        foreach ($wallets as $wallet) {
            $activeAmount = (string) ($activeAmounts[$wallet->currency_id] ?? 0);
            $stakingAmount = (string) ($stakingAmounts[$wallet->currency_id] ?? 0);
            $custodyAmount = math_sum($activeAmount, $stakingAmount);

            $wallet->setAttribute('auto_invest_locked_amount', $activeAmount);
            $wallet->setAttribute('staking_locked_amount', $stakingAmount);
            $wallet->setAttribute('custody_locked_amount', $custodyAmount);

            if (math_compare($custodyAmount, 0) > 0) {
                $wallet->setAttribute(
                    'balance_in_lc',
                    math_sum($wallet->balance_in_lc ?? 0, $custodyAmount)
                );
            }
        }

        return $wallets;
    }

    private function getActiveStakingAmountsByCurrency(int $userId, array $currencyIds)
    {
        if (
            empty($currencyIds) ||
            !$this->hasTableCached('staking_users') ||
            !$this->hasColumnCached('staking_users', 'user_id') ||
            !$this->hasColumnCached('staking_users', 'amount')
        ) {
            return collect();
        }

        $hasCurrencyId = $this->hasColumnCached('staking_users', 'currency_id');

        if (!$hasCurrencyId) {
            return collect();
        }

        $query = \Illuminate\Support\Facades\DB::table('staking_users')
            ->selectRaw('currency_id, SUM(amount) as active_staking_amount')
            ->where('user_id', $userId)
            ->whereIn('currency_id', $currencyIds)
            ->groupBy('currency_id');

        if ($this->hasColumnCached('staking_users', 'status')) {
            $query->where('status', 'active');
        }

        return $query->pluck('active_staking_amount', 'currency_id');
    }

    private function getActiveAutoInvestOrdersForWalletSummary(int $userId)
    {
        if ($userId <= 0 || !$this->hasTableCached('auto_invest_orders')) {
            return collect();
        }

        if (!array_key_exists($userId, static::$activeAutoInvestOrdersCache)) {
            static::$activeAutoInvestOrdersCache[$userId] = \Illuminate\Support\Facades\DB::table('auto_invest_orders')
                ->select([
                    'currency_id',
                    'amount',
                    'used_margin',
                    'meta',
                ])
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->get();
        }

        return static::$activeAutoInvestOrdersCache[$userId];
    }

    private function sumActiveAutoInvestOrdersByCurrency(
        int $userId,
        array $currencyIds,
        bool $availableOnly
    ) {
        $allowedCurrencyIds = array_fill_keys(array_map('intval', $currencyIds), true);
        $totals = [];

        foreach ($this->getActiveAutoInvestOrdersForWalletSummary($userId) as $order) {
            $currencyId = (int) ($order->currency_id ?? 0);

            if ($currencyId <= 0 || !isset($allowedCurrencyIds[$currencyId])) {
                continue;
            }

            $amount = $this->safeDecimal($order->amount ?? 0);

            if ($availableOnly) {
                $amount = $this->safeDecimal(math_sub(
                    $amount,
                    $this->safeDecimal($order->used_margin ?? 0)
                ));

                if (math_compare($amount, 0) < 0) {
                    $amount = '0';
                }
            }

            if (math_compare($amount, 0) <= 0) {
                continue;
            }

            $totals[$currencyId] = math_sum($totals[$currencyId] ?? 0, $amount);
        }

        return collect($totals);
    }

    private function appendRealAndVirtualBalancesToWallets($wallets)
    {
        foreach ($wallets as $wallet) {
            $balances = $this->buildWalletBalancesPayload($wallet);

            $wallet->setAttribute('real_balances', $balances['real']);
            $wallet->setAttribute('virtual_balances', $balances['virtual']);
            $wallet->setAttribute('total_balances', $balances['total']);

            $wallet->setAttribute('balance_in_virtual_wallet', $balances['balance_in_virtual_wallet']);
            $wallet->setAttribute('balance_in_virtual_trade', $balances['balance_in_virtual_trade']);
            $wallet->setAttribute('balance_in_virtual_order', $balances['balance_in_virtual_order']);
            $wallet->setAttribute('balance_in_virtual_withdraw', $balances['balance_in_virtual_withdraw']);
            $wallet->setAttribute('virtual_available_for_withdraw', $balances['virtual_available_for_withdraw']);

            $wallet->setAttribute('total_balance_in_wallet', $balances['total']['wallet']);
            $wallet->setAttribute('total_balance_in_trade', $balances['total']['trade']);
            $wallet->setAttribute('total_balance_in_order', $balances['total']['order']);
            $wallet->setAttribute('total_balance_all', $balances['total']['all']);
        }

        return $wallets;
    }

    private function appendWalletsUsdtSummary($wallets, int $userId)
    {
        $isUserVirtual = $this->isWalletOwnerVirtual($userId);

        $realWalletUsdtTotal = '0';
        $virtualWalletUsdtTotal = '0';

        foreach ($wallets as $wallet) {
            $currencyId = (int) ($wallet->currency_id ?? 0);

            if ($currencyId <= 0) {
                continue;
            }

            /*
             * 当前币种真实余额：
             * 资金账户 + 交易账户 + 挂单账户 + LC账户。
             */
            $realAmount = math_sum(
                math_sum(
                    math_sum($wallet->balance_in_wallet ?? 0, $wallet->balance_in_trade ?? 0),
                    $wallet->balance_in_order ?? 0
                ),
                $wallet->balance_in_lc ?? 0
            );

            /*
             * 当前币种虚拟余额：
             * 虚拟资金账户 + 虚拟交易账户 + 虚拟挂单账户。
             */
            $virtualAmount = math_sum(
                math_sum($wallet->balance_in_virtual_wallet ?? 0, $wallet->balance_in_virtual_trade ?? 0),
                $wallet->balance_in_virtual_order ?? 0
            );

            /*
             * 如果 users.is_xn = true：
             * 真实字段里的资产也全部归到虚拟资产里。
             */
            if ($isUserVirtual) {
                $virtualAmount = math_sum($virtualAmount, $realAmount);
                $realAmount = '0';
            }

            /*
             * 所有币种都折算成 USDT。
             */
            $realUsdtAmount = $this->convertWalletAmountToUsdt($realAmount, $currencyId);
            $virtualUsdtAmount = $this->convertWalletAmountToUsdt($virtualAmount, $currencyId);

            $realWalletUsdtTotal = math_sum($realWalletUsdtTotal, $realUsdtAmount);
            $virtualWalletUsdtTotal = math_sum($virtualWalletUsdtTotal, $virtualUsdtAmount);

            /*
             * 每个币种自己的 USDT 折算结果。
             */
            $wallet->setAttribute('real_total_usdt', $this->safeDecimal($realUsdtAmount, 2));
            $wallet->setAttribute('virtual_total_usdt', $this->safeDecimal($virtualUsdtAmount, 2));
            $wallet->setAttribute(
                'wallet_total_usdt',
                $this->safeDecimal(math_sum($realUsdtAmount, $virtualUsdtAmount), 2)
            );
        }

        /*
         * 所有 active 理财订单也全部折算成 USDT。
         * 这里不会限制币种，BTC / ETH / OKR / USDT 都会计算。
         */
        $autoInvestSummary = $this->getAutoInvestUsdtSummary($userId, $isUserVirtual);
        $stakingUsdtTotal = $this->getStakingUsdtSummary($userId);

        $realAutoInvestUsdt = $autoInvestSummary['real_usdt'];
        $virtualAutoInvestUsdt = $autoInvestSummary['virtual_usdt'];
        $autoInvestUsdtTotal = $autoInvestSummary['total_usdt'];

        $realAutoInvestAvailableUsdt = $autoInvestSummary['real_available_usdt'];
        $virtualAutoInvestAvailableUsdt = $autoInvestSummary['virtual_available_usdt'];
        $autoInvestAvailableUsdtTotal = $autoInvestSummary['available_total_usdt'];

        /*
         * 最终总资产：
         * 钱包余额折算 USDT + 理财订单折算 USDT。
         */
        $realAssetsUsdtTotal = math_sum($realWalletUsdtTotal, $realAutoInvestUsdt);
        $virtualAssetsUsdtTotal = math_sum($virtualWalletUsdtTotal, $virtualAutoInvestUsdt);
        $allAssetsUsdtTotal = math_sum(
            math_sum($realAssetsUsdtTotal, $virtualAssetsUsdtTotal),
            $stakingUsdtTotal
        );
        $custodyUsdtTotal = math_sum($autoInvestUsdtTotal, $stakingUsdtTotal);

        $walletsTotalUsdt = math_sum($realWalletUsdtTotal, $virtualWalletUsdtTotal);

        /*
         * 这些总资产字段，每条钱包都返回一份，方便前端直接取。
         */
        foreach ($wallets as $wallet) {
            $wallet->setAttribute('auto_invest_total_usdt', $this->safeDecimal($autoInvestUsdtTotal, 2));
            $wallet->setAttribute('earn_total_usdt', $this->safeDecimal($autoInvestUsdtTotal, 2));
            $wallet->setAttribute('auto_invest_real_usdt', $this->safeDecimal($realAutoInvestUsdt, 2));
            $wallet->setAttribute('auto_invest_virtual_usdt', $this->safeDecimal($virtualAutoInvestUsdt, 2));
            $wallet->setAttribute('staking_total_usdt', $this->safeDecimal($stakingUsdtTotal, 2));
            $wallet->setAttribute('custody_total_usdt', $this->safeDecimal($custodyUsdtTotal, 2));

            $wallet->setAttribute('auto_invest_available_total_usdt', $this->safeDecimal($autoInvestAvailableUsdtTotal, 2));
            $wallet->setAttribute('auto_invest_available_real_usdt', $this->safeDecimal($realAutoInvestAvailableUsdt, 2));
            $wallet->setAttribute('auto_invest_available_virtual_usdt', $this->safeDecimal($virtualAutoInvestAvailableUsdt, 2));

            $wallet->setAttribute('wallets_real_total_usdt', $this->safeDecimal($realWalletUsdtTotal, 2));
            $wallet->setAttribute('wallets_virtual_total_usdt', $this->safeDecimal($virtualWalletUsdtTotal, 2));
            $wallet->setAttribute('wallets_total_usdt', $this->safeDecimal($walletsTotalUsdt, 2));

            $wallet->setAttribute('all_assets_total_usdt', $this->safeDecimal($allAssetsUsdtTotal, 2));
            $wallet->setAttribute('all_real_assets_total_usdt', $this->safeDecimal($realAssetsUsdtTotal, 2));
            $wallet->setAttribute('all_virtual_assets_total_usdt', $this->safeDecimal($virtualAssetsUsdtTotal, 2));
        }

        /*
         * 关键：
         * 找到 USDT 钱包，把所有代币折算后的 USDT 数量加到 USDT 钱包数量里面。
         *
         * 这里只改接口返回，不修改数据库余额。
         */
        foreach ($wallets as $wallet) {
            if (!$this->isUsdtWallet($wallet)) {
                continue;
            }

            $allAssetsUsdt = $this->safeDecimal($allAssetsUsdtTotal, 2);
            $realAssetsUsdt = $this->safeDecimal($realAssetsUsdtTotal, 2);
            $virtualAssetsUsdt = $this->safeDecimal($virtualAssetsUsdtTotal, 2);

            /*
             * 给前端展示用的新字段。
             */
            $wallet->setAttribute('converted_all_tokens_to_usdt', $allAssetsUsdt);
            $wallet->setAttribute('converted_real_tokens_to_usdt', $realAssetsUsdt);
            $wallet->setAttribute('converted_virtual_tokens_to_usdt', $virtualAssetsUsdt);

            /*
             * 兼容常见前端字段：
             * 如果前端读取 USDT 钱包的 total_balance_all / total_balance，
             * 这里也让它显示全币种折算后的 USDT 总额。
             */
            $wallet->setAttribute('total_balance_all', $allAssetsUsdt);
            $wallet->setAttribute('total_balance', $allAssetsUsdt);
            $wallet->setAttribute('total_usdt_balance', $allAssetsUsdt);
            $wallet->setAttribute('display_usdt_balance', $allAssetsUsdt);

            /*
             * 兼容 total_balances 结构。
             */
            $totalBalances = $wallet->getAttribute('total_balances');

            if (is_array($totalBalances)) {
                $totalBalances['all'] = $allAssetsUsdt;
                $totalBalances['all_assets_usdt'] = $allAssetsUsdt;
                $totalBalances['real_assets_usdt'] = $realAssetsUsdt;
                $totalBalances['virtual_assets_usdt'] = $virtualAssetsUsdt;

                $wallet->setAttribute('total_balances', $totalBalances);
            }

            break;
        }

        return $wallets;
    }

    private function getUsdtCurrencyIds(): array
    {
        if (static::$usdtCurrencyIdsCache !== null) {
            return static::$usdtCurrencyIdsCache;
        }

        $resolver = function () {
            return \Illuminate\Support\Facades\DB::table('currencies')
                ->whereRaw('UPPER(symbol) = ?', ['USDT'])
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        };

        try {
            static::$usdtCurrencyIdsCache = Cache::store(
                (string) config('performance.cache_store', 'redis')
            )->remember(
                'wallet:metadata:usdt-currency-ids:v1:' . config('database.default'),
                3600,
                $resolver
            );
        } catch (\Throwable $e) {
            static::$usdtCurrencyIdsCache = $resolver();
        }

        return static::$usdtCurrencyIdsCache;
    }

    private function getCurrencySymbolCached(int $currencyId): string
    {
        if ($currencyId <= 0) {
            return '';
        }

        if (!array_key_exists($currencyId, static::$currencySymbolCache)) {
            $resolver = function () use ($currencyId) {
                return \Illuminate\Support\Facades\DB::table('currencies')
                    ->where('id', $currencyId)
                    ->value('symbol');
            };

            try {
                $symbol = Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->remember(
                    'wallet:metadata:currency-symbol:v1:' . config('database.default') . ':' . $currencyId,
                    3600,
                    $resolver
                );
            } catch (\Throwable $e) {
                $symbol = $resolver();
            }

            static::$currencySymbolCache[$currencyId] = $symbol
                ? strtoupper(trim((string) $symbol))
                : '';
        }

        return static::$currencySymbolCache[$currencyId];
    }

    private function getUsdQuoteCurrencyIds(): array
    {
        if (static::$usdQuoteCurrencyIdsCache !== null) {
            return static::$usdQuoteCurrencyIdsCache;
        }

        $resolver = function () {
            return \Illuminate\Support\Facades\DB::table('currencies')
                ->whereRaw('UPPER(symbol) IN (?, ?)', ['USDT', 'USD'])
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->unique()
                ->values()
                ->all();
        };

        try {
            static::$usdQuoteCurrencyIdsCache = Cache::store(
                (string) config('performance.cache_store', 'redis')
            )->remember(
                'wallet:metadata:usd-quote-currency-ids:v1:' . config('database.default'),
                3600,
                $resolver
            );
        } catch (\Throwable $e) {
            static::$usdQuoteCurrencyIdsCache = $resolver();
        }

        return static::$usdQuoteCurrencyIdsCache;
    }

    private function getWalletUsdMarketPriceMap(array $quoteCurrencyIds): array
    {
        $quoteCurrencyIds = array_values(array_unique(array_map('intval', array_filter($quoteCurrencyIds))));

        if (empty($quoteCurrencyIds)) {
            return [];
        }

        $cacheKey = implode(':', $quoteCurrencyIds);

        if (isset(static::$walletUsdMarketPriceMapCache[$cacheKey])) {
            return static::$walletUsdMarketPriceMapCache[$cacheKey];
        }

        $resolver = function () use ($quoteCurrencyIds) {
            $markets = \Illuminate\Support\Facades\DB::table('markets')
                ->select(['base_currency_id', 'quote_currency_id', 'last'])
                ->whereNotNull('last')
                ->where('last', '>', 0)
                ->where(function ($query) use ($quoteCurrencyIds) {
                    $query->whereIn('quote_currency_id', $quoteCurrencyIds)
                        ->orWhereIn('base_currency_id', $quoteCurrencyIds);
                })
                ->get();

            $map = [];

            foreach ($markets as $market) {
                $baseCurrencyId = (int) ($market->base_currency_id ?? 0);
                $quoteCurrencyId = (int) ($market->quote_currency_id ?? 0);

                if ($baseCurrencyId <= 0 || $quoteCurrencyId <= 0 || !is_numeric($market->last) || (float) $market->last <= 0) {
                    continue;
                }

                $map[$baseCurrencyId . ':' . $quoteCurrencyId] = math_formatter($market->last, 18, '.', '');
            }

            return $map;
        };

        try {
            $map = Cache::store(
                (string) config('performance.cache_store', 'redis')
            )->remember(
                'wallet:metadata:usd-market-map:v1:' . config('database.default') . ':' . sha1($cacheKey),
                5,
                $resolver
            );
        } catch (\Throwable $e) {
            $map = $resolver();
        }

        return static::$walletUsdMarketPriceMapCache[$cacheKey] = $map;
    }

    private function isUsdtWallet($wallet): bool
    {
        $symbolCandidates = [
            $wallet->symbol ?? null,
            $wallet->currency_symbol ?? null,
            $wallet->currency && isset($wallet->currency->symbol) ? $wallet->currency->symbol : null,
        ];

        foreach ($symbolCandidates as $symbol) {
            if ($symbol && strtoupper(trim((string) $symbol)) === 'USDT') {
                return true;
            }
        }

        $currencyId = (int) ($wallet->currency_id ?? 0);

        if ($currencyId <= 0) {
            return false;
        }

        return in_array($currencyId, $this->getUsdtCurrencyIds(), true);
    }


    private function normalizeVirtualUserWalletsToUsdt($wallets, int $userId)
    {
        $usdtWallet = null;

        /*
         * 最新口径：
         * users.is_xn = true 时，不再把所有非 USDT 钱包余额都折算后加入 USDT。
         * 这里只做两件事：
         * 1. 保留 USDT 钱包原本的虚拟余额，例如 balance_in_virtual_trade = 47783.7600。
         * 2. 把所有 active 理财订单折算成 USDT 后，加到 USDT.balance_in_virtual_trade。
         *
         * 例如：
         * USDT.balance_in_virtual_trade = 47783.7600
         * BTC 理财折算 = 8032.975511
         * USDT 理财折算 = 1005.9128
         *
         * 最终：
         * USDT.balance_in_virtual_trade = 47783.7600 + 8032.975511 + 1005.9128
         *
         * 非理财的 BTC / OKR / ETH 钱包余额，不在这里折算加入 USDT。
         */
        $usdtVirtualWallet = '0';
        $usdtVirtualTrade = '0';
        $usdtVirtualOrder = '0';
        $usdtVirtualWithdraw = '0';

        foreach ($wallets as $wallet) {
            if ($this->isUsdtWallet($wallet)) {
                $usdtWallet = $wallet;

                /*
                 * 只取 USDT 钱包本身已有的虚拟余额作为基础值。
                 * 真实字段不计入虚拟基础值，避免真实账户资产混入。
                 */
                $usdtVirtualWallet = $this->safeDecimal($wallet->balance_in_virtual_wallet ?? 0, 18);
                $usdtVirtualTrade = $this->safeDecimal($wallet->balance_in_virtual_trade ?? 0, 18);
                $usdtVirtualOrder = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0, 18);
                $usdtVirtualWithdraw = $this->safeDecimal($wallet->balance_in_virtual_withdraw ?? 0, 18);
            }

            /*
             * 虚拟账户下，所有真实字段都清零返回。
             * 非 USDT 币种也清零，避免前端重复展示非 USDT 余额。
             */
            $wallet->setAttribute('balance_in_wallet', 0);
            $wallet->setAttribute('balance_in_trade', 0);
            $wallet->setAttribute('balance_in_lc', 0);
            $wallet->setAttribute('balance_in_order', 0);
            $wallet->setAttribute('balance_in_withdraw', 0);

            if (!$this->isUsdtWallet($wallet)) {
                $wallet->setAttribute('balance_in_virtual_wallet', 0);
                $wallet->setAttribute('balance_in_virtual_trade', 0);
                $wallet->setAttribute('balance_in_virtual_order', 0);

                if ($this->transferWalletColumnExists('balance_in_virtual_withdraw')) {
                    $wallet->setAttribute('balance_in_virtual_withdraw', 0);
                }
            }
        }

        /*
         * 合约页面只把 active 理财订单的“可用保证金”加入虚拟交易账户展示。
         *
         * 重点：
         * 1. auto_invest_orders.amount 是理财订单总本金。
         * 2. auto_invest_orders.used_margin 是已经被合约开仓占用的保证金。
         * 3. 合约页面可用余额必须使用 amount - used_margin。
         *
         * 例如：
         * BTC 理财折算 USDT = 8032.975511
         * 已经被开仓占用 1000 USDT
         * 合约页面只展示 7032.975511 可用理财保证金。
         */
        $autoInvestSummary = $this->getAutoInvestUsdtSummary($userId, true);
        $autoInvestTotalUsdt = $autoInvestSummary['total_usdt'] ?? '0';
        $autoInvestAvailableUsdt = $autoInvestSummary['available_total_usdt'] ?? '0';

        /*
         * 这里必须加 available_total_usdt，不要加 total_usdt。
         * 否则开仓后 used_margin 已经占用的理财保证金还会继续显示成可用余额。
         */
        $finalVirtualTradeUsdt = math_sum($usdtVirtualTrade, $autoInvestAvailableUsdt);

        $allVirtualAssetsUsdt = math_sum(
            math_sum($usdtVirtualWallet, $finalVirtualTradeUsdt),
            math_sum($usdtVirtualOrder, $usdtVirtualWithdraw)
        );

        if ($usdtWallet) {
            $usdtWallet->setAttribute('balance_in_wallet', 0);
            $usdtWallet->setAttribute('balance_in_trade', 0);
            $usdtWallet->setAttribute('balance_in_lc', 0);
            $usdtWallet->setAttribute('balance_in_order', 0);
            $usdtWallet->setAttribute('balance_in_withdraw', 0);

            $usdtWallet->setAttribute('balance_in_virtual_wallet', $this->safeDecimal($usdtVirtualWallet, 4));
            $usdtWallet->setAttribute('balance_in_virtual_trade', $this->safeDecimal($finalVirtualTradeUsdt, 4));
            $usdtWallet->setAttribute('balance_in_virtual_order', $this->safeDecimal($usdtVirtualOrder, 4));

            if ($this->transferWalletColumnExists('balance_in_virtual_withdraw')) {
                $usdtWallet->setAttribute('balance_in_virtual_withdraw', $this->safeDecimal($usdtVirtualWithdraw, 4));
            }

            $usdtWallet->setAttribute('virtual_all_tokens_wallet_usdt', $this->safeDecimal($usdtVirtualWallet, 4));
            $usdtWallet->setAttribute('virtual_all_tokens_trade_usdt', $this->safeDecimal($finalVirtualTradeUsdt, 4));
            $usdtWallet->setAttribute('virtual_all_tokens_order_usdt', $this->safeDecimal($usdtVirtualOrder, 4));
            $usdtWallet->setAttribute('virtual_all_tokens_withdraw_usdt', $this->safeDecimal($usdtVirtualWithdraw, 4));

            $autoInvestUsedUsdt = math_sub($autoInvestTotalUsdt, $autoInvestAvailableUsdt);

            if (math_compare($autoInvestUsedUsdt, 0) < 0) {
                $autoInvestUsedUsdt = '0';
            }

            /*
             * total 表示理财总额，available 表示可用于新开仓的理财保证金，
             * used 表示已经被合约开仓占用的理财保证金。
             */
            $usdtWallet->setAttribute('auto_invest_total_usdt', $this->safeDecimal($autoInvestTotalUsdt, 2));
            $usdtWallet->setAttribute('auto_invest_virtual_usdt', $this->safeDecimal($autoInvestTotalUsdt, 2));
            $usdtWallet->setAttribute('auto_invest_available_total_usdt', $this->safeDecimal($autoInvestAvailableUsdt, 2));
            $usdtWallet->setAttribute('auto_invest_available_virtual_usdt', $this->safeDecimal($autoInvestAvailableUsdt, 2));
            $usdtWallet->setAttribute('auto_invest_used_total_usdt', $this->safeDecimal($autoInvestUsedUsdt, 2));
            $usdtWallet->setAttribute('auto_invest_used_virtual_usdt', $this->safeDecimal($autoInvestUsedUsdt, 2));

            $usdtWallet->setAttribute('all_assets_total_usdt', $this->safeDecimal($allVirtualAssetsUsdt, 2));
            $usdtWallet->setAttribute('all_real_assets_total_usdt', '0');
            $usdtWallet->setAttribute('all_virtual_assets_total_usdt', $this->safeDecimal($allVirtualAssetsUsdt, 2));
            $usdtWallet->setAttribute('converted_all_tokens_to_usdt', $this->safeDecimal($allVirtualAssetsUsdt, 2));
            $usdtWallet->setAttribute('converted_real_tokens_to_usdt', '0');
            $usdtWallet->setAttribute('converted_virtual_tokens_to_usdt', $this->safeDecimal($allVirtualAssetsUsdt, 2));
            $usdtWallet->setAttribute('display_usdt_balance', $this->safeDecimal($allVirtualAssetsUsdt, 2));
            $usdtWallet->setAttribute('total_usdt_balance', $this->safeDecimal($allVirtualAssetsUsdt, 2));
        }

        return $wallets;
    }

    private function getAutoInvestUsdtSummary(int $userId, bool $isUserVirtual = false): array
    {
        if (!$this->hasTableCached('auto_invest_orders')) {
            return [
                'real_usdt' => '0',
                'virtual_usdt' => '0',
                'total_usdt' => '0',
                'real_available_usdt' => '0',
                'virtual_available_usdt' => '0',
                'available_total_usdt' => '0',
            ];
        }

        /*
         * 这里不要限制 currency_id。
         * 所有 active 理财订单都查出来，然后统一折算成 USDT。
         */
        $orders = $this->getActiveAutoInvestOrdersForWalletSummary($userId);

        $realUsdtTotal = '0';
        $virtualUsdtTotal = '0';

        $realAvailableUsdtTotal = '0';
        $virtualAvailableUsdtTotal = '0';

        foreach ($orders as $order) {
            $currencyId = (int) ($order->currency_id ?? 0);

            if ($currencyId <= 0) {
                continue;
            }

            $amount = $this->safeDecimal($order->amount ?? 0);
            $usedMargin = $this->safeDecimal($order->used_margin ?? 0);

            if (math_compare($amount, 0) <= 0) {
                continue;
            }

            /*
             * 总资产使用完整理财金额 amount。
             */
            $orderUsdt = $this->convertWalletAmountToUsdt($amount, $currencyId);

            /*
             * 可用保证金使用 amount - used_margin。
             */
            $availableAmount = math_sub($amount, $usedMargin);

            if (math_compare($availableAmount, 0) < 0) {
                $availableAmount = '0';
            }

            $availableUsdt = $this->convertWalletAmountToUsdt($availableAmount, $currencyId);

            /*
             * users.is_xn = true 时，这个用户所有理财订单都算虚拟理财。
             */
            $isVirtualOrder = $isUserVirtual;

            if (!$isVirtualOrder && !empty($order->meta)) {
                $meta = json_decode($order->meta, true);

                if (is_array($meta)) {
                    $sourceAccountType = $meta['source_account_type'] ?? null;
                    $sourceBalanceField = $meta['source_balance_field'] ?? null;

                    if (
                        $sourceAccountType === 'virtual' ||
                        $sourceBalanceField === 'balance_in_virtual_trade' ||
                        $sourceBalanceField === 'balance_in_virtual_wallet'
                    ) {
                        $isVirtualOrder = true;
                    }
                }
            }

            if ($isVirtualOrder) {
                $virtualUsdtTotal = math_sum($virtualUsdtTotal, $orderUsdt);
                $virtualAvailableUsdtTotal = math_sum($virtualAvailableUsdtTotal, $availableUsdt);
            } else {
                $realUsdtTotal = math_sum($realUsdtTotal, $orderUsdt);
                $realAvailableUsdtTotal = math_sum($realAvailableUsdtTotal, $availableUsdt);
            }
        }

        return [
            'real_usdt' => $realUsdtTotal,
            'virtual_usdt' => $virtualUsdtTotal,
            'total_usdt' => math_sum($realUsdtTotal, $virtualUsdtTotal),

            'real_available_usdt' => $realAvailableUsdtTotal,
            'virtual_available_usdt' => $virtualAvailableUsdtTotal,
            'available_total_usdt' => math_sum($realAvailableUsdtTotal, $virtualAvailableUsdtTotal),
        ];
    }

    private function getStakingUsdtSummary(int $userId): string
    {
        if (!$this->hasTableCached('staking_users')) {
            return '0';
        }

        if (
            !$this->hasColumnCached('staking_users', 'user_id') ||
            !$this->hasColumnCached('staking_users', 'amount')
        ) {
            return '0';
        }

        $selectColumns = ['amount'];
        $hasCurrencyId = $this->hasColumnCached('staking_users', 'currency_id');
        $hasStakingId = $this->hasColumnCached('staking_users', 'staking_id');
        $hasMeta = $this->hasColumnCached('staking_users', 'meta');

        if ($hasCurrencyId) {
            $selectColumns[] = 'currency_id';
        }

        if ($hasStakingId) {
            $selectColumns[] = 'staking_id';
        }

        if ($hasMeta) {
            $selectColumns[] = 'meta';
        }

        $query = \Illuminate\Support\Facades\DB::table('staking_users')
            ->select($selectColumns)
            ->where('user_id', $userId);

        if ($this->hasColumnCached('staking_users', 'status')) {
            $query->where('status', 'active');
        }

        $orders = $query->get();

        $total = '0';

        foreach ($orders as $order) {
            $currencyId = $hasCurrencyId ? (int) ($order->currency_id ?? 0) : 0;

            if ($currencyId <= 0 && $hasStakingId) {
                $currencyId = $this->getStakingProductCurrencyId((int) ($order->staking_id ?? 0));
            }

            if ($currencyId <= 0 && $hasMeta) {
                $currencyId = $this->getCurrencyIdFromStakingMeta($order->meta ?? null);
            }

            $amount = $this->safeDecimal($order->amount ?? 0);

            if ($currencyId <= 0 || math_compare($amount, 0) <= 0) {
                continue;
            }

            $total = math_sum(
                $total,
                $this->convertWalletAmountToUsdt($amount, $currencyId)
            );
        }

        return $total;
    }

    private function getStakingProductCurrencyId(int $stakingId): int
    {
        if (
            $stakingId <= 0 ||
            !$this->hasTableCached('staking') ||
            !$this->hasColumnCached('staking', 'currency_id')
        ) {
            return 0;
        }

        if (array_key_exists($stakingId, static::$stakingProductCurrencyCache)) {
            return static::$stakingProductCurrencyCache[$stakingId];
        }

        return static::$stakingProductCurrencyCache[$stakingId] = (int) \Illuminate\Support\Facades\DB::table('staking')
            ->where('id', $stakingId)
            ->value('currency_id');
    }

    private function getCurrencyIdFromStakingMeta($meta): int
    {
        if (empty($meta)) {
            return 0;
        }

        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($meta)) {
            return 0;
        }

        foreach (['currency_id', 'stake_currency_id', 'source_currency_id'] as $key) {
            $currencyId = (int) ($meta[$key] ?? 0);

            if ($currencyId > 0) {
                return $currencyId;
            }
        }

        return in_array($currencyId, $this->getUsdtCurrencyIds(), true);
    }

    private function isWalletOwnerVirtual(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (array_key_exists($userId, static::$walletOwnerVirtualCache)) {
            return static::$walletOwnerVirtualCache[$userId];
        }

        if (!$this->hasTableCached('users')) {
            return static::$walletOwnerVirtualCache[$userId] = false;
        }

        if (!$this->hasColumnCached('users', 'is_xn')) {
            return static::$walletOwnerVirtualCache[$userId] = false;
        }

        return static::$walletOwnerVirtualCache[$userId] = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $userId)
            ->where('is_xn', true)
            ->exists();
    }

    private function convertWalletAmountToUsdt($amount, int $currencyId): string
    {
        if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0) {
            return '0';
        }

        $symbol = $this->getCurrencySymbolCached($currencyId);

        if ($symbol === '') {
            return '0';
        }

        if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
            return math_formatter($amount, 8, '.', '');
        }

        $rate = $this->getWalletCurrencyToUsdtRate($currencyId);

        if (!is_numeric($rate) || (float) $rate <= 0) {
            return '0';
        }

        return math_formatter(
            math_multiply($amount, $rate),
            8,
            '.',
            ''
        );
    }

    private function getWalletCurrencyToUsdtRate(int $currencyId): string
    {
        static $rateCache = [];

        if ($currencyId <= 0) {
            return '0';
        }

        if (isset($rateCache[$currencyId])) {
            return $rateCache[$currencyId];
        }

        $symbol = $this->getCurrencySymbolCached($currencyId);

        if ($symbol === '') {
            $rateCache[$currencyId] = '0';
            return '0';
        }

        if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
            $rateCache[$currencyId] = '1';
            return '1';
        }

        $quoteIds = $this->getUsdQuoteCurrencyIds();

        if (empty($quoteIds)) {
            $rateCache[$currencyId] = '0';
            return '0';
        }

        $marketPriceMap = $this->getWalletUsdMarketPriceMap($quoteIds);

        foreach ($quoteIds as $quoteId) {
            $mapKey = $currencyId . ':' . (int) $quoteId;

            if (isset($marketPriceMap[$mapKey]) && is_numeric($marketPriceMap[$mapKey]) && (float) $marketPriceMap[$mapKey] > 0) {
                $rateCache[$currencyId] = math_formatter($marketPriceMap[$mapKey], 18, '.', '');
                return $rateCache[$currencyId];
            }
        }

        foreach ($quoteIds as $baseQuoteId) {
            $mapKey = (int) $baseQuoteId . ':' . $currencyId;

            if (isset($marketPriceMap[$mapKey]) && is_numeric($marketPriceMap[$mapKey]) && (float) $marketPriceMap[$mapKey] > 0) {
                $rateCache[$currencyId] = math_formatter(
                    math_divide(1, $marketPriceMap[$mapKey]),
                    18,
                    '.',
                    ''
                );

                return $rateCache[$currencyId];
            }
        }

        $currencyModel = \App\Models\Currency\Currency::find($currencyId);

        if ($currencyModel) {
            $fallbackRate = (new CurrencyRepository())->currencyPriceInUsd($currencyModel);

            if (is_numeric($fallbackRate) && (float) $fallbackRate > 0) {
                $rateCache[$currencyId] = math_formatter($fallbackRate, 18, '.', '');

                return $rateCache[$currencyId];
            }
        }

        $rateCache[$currencyId] = '0';

        return '0';
    }

    /**
     * Get Deposit Address
     *
     * Generates or retrieves a deposit address for a specific cryptocurrency and network.
     *
     * @operationId getDepositAddress
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('symbol', description: 'The currency symbol', required: true, type: 'string', example: 'BTC')]
    #[QueryParameter('network', description: 'The network ID to use for deposit', required: true, type: 'integer', example: 1)]
    public function getAddress(GetAddressRequest $request)
    {
        $symbol = $request->get('symbol', null);
        $network = $request->get('network', null);

        $currency = (new CurrencyService())->getCurrencyBySymbol($symbol);

        if (!$currency) {
            return response()->json([
                'success' => false,
                'message' => __('Currency not found'),
            ], 422);
        }

        if (!filter_var($currency->deposit_status, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'success' => false,
                'message' => __('Deposits are not allowed for this currency'),
            ], 403);
        }

        $networks = Network::where('deposit_status', false)->pluck('id')->toArray();

        $currencyDisabledNetworks = $currency->disabled_deposit_networks ?? [];

        if (is_string($currencyDisabledNetworks)) {
            $decoded = json_decode($currencyDisabledNetworks, true);
            $currencyDisabledNetworks = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($currencyDisabledNetworks)) {
            $currencyDisabledNetworks = [];
        }

        $disabledNetworks = array_merge($networks, $currencyDisabledNetworks);

        if ($network && in_array((int) $network, array_map('intval', $disabledNetworks), true)) {
            return response()->json([
                'success' => false,
                'message' => __('Deposits are not allowed for this network'),
            ], 403);
        }

        if ($channelError = app(\App\Services\Deposit\DepositChannelPolicy::class)->error((int)$currency->id, (int)$network, true, auth()->id())) {
            return response()->json(['success'=>false,'message'=>__($channelError === 'Deposit scanner needs attention' ? 'Deposits are temporarily paused while the network scanner recovers. Please try again later.' : 'Deposits are temporarily unavailable for this network. Please contact support.')],403);
        }

        $result = (new WalletService())->generateWalletAddress(
            $symbol,
            auth()->user()->getAuthIdentifier(),
            $network
        );

        $channel = \App\Models\Deposit\DepositChannel::where('currency_id', $currency->id)->where('network_id', $network)->first();
        $result['data']['deposit_rules'] = \App\Services\Wallet\NetworkRules::deposit($currency, (int)$network, $channel);
        return response()->json($result['data'], $result['status']);
    }

    /**
     * Get Crypto Deposits
     *
     * Retrieves cryptocurrency deposit history for the authenticated user.
     * Includes pending, confirmed, and failed deposits.
     *
     * @operationId getCryptoDeposits
     * @authenticated
     *
     * @urlParam type string Deposit type filter. Example: coin
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": "550e8400-e29b-41d4-a716-446655440000",
     *       "currency": "BTC",
     *       "amount": "0.5",
     *       "network": "Bitcoin",
     *       "txid": "abc123def456...",
     *       "address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
     *       "confirmations": 3,
     *       "required_confirmations": 3,
     *       "status": "confirmed",
     *       "created_at": "2024-01-15 10:30:00"
     *     }
     *   ],
     *   "links": {...},
     *   "meta": {...}
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeposits(Request $request)
    {
        $request->validate(['currency'=>['nullable','integer','min:1'], 'network'=>['nullable','integer','min:1']]);
        $depositRepository = new DepositRepository();

        $deposits = Deposit::collection($depositRepository->getReportUser(auth()->user(), false))->response()->getData(true);

        return response()->json($deposits);
    }

    /**
     * Get Fiat Deposits
     *
     * Retrieves fiat currency deposit history for the authenticated user.
     * Includes deposits made via bank transfer, card payments, etc.
     *
     * @operationId getFiatDeposits
     * @authenticated
     *
     * @urlParam type string Deposit type filter: "all", "pending", "completed". Default: all. Example: all
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": "550e8400-e29b-41d4-a716-446655440000",
     *       "currency": "USD",
     *       "amount": "1000.00",
     *       "payment_method": "bank_transfer",
     *       "reference": "DEP123456",
     *       "status": "completed",
     *       "created_at": "2024-01-15 10:30:00"
     *     }
     *   ],
     *   "links": {...},
     *   "meta": {...}
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFiatDeposits(Request $request, $type = 'all')
    {
        $depositRepository = new FiatDepositRepository();

        $deposits = FiatDeposit::collection($depositRepository->getReportUser(auth()->user(), 'all', false))->response()->getData(true);

        return response()->json($deposits);
    }

    /**
     * Get Crypto Withdrawals
     *
     * Retrieves cryptocurrency withdrawal history for the authenticated user.
     * Includes pending, processing, completed, and rejected withdrawals.
     *
     * @operationId getCryptoWithdrawals
     * @authenticated
     *
     * @urlParam type string Withdrawal type filter. Example: coin
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": "550e8400-e29b-41d4-a716-446655440000",
     *       "currency": "BTC",
     *       "amount": "0.5",
     *       "fee": "0.0005",
     *       "net_amount": "0.4995",
     *       "network": "Bitcoin",
     *       "address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
     *       "txid": "abc123def456...",
     *       "status": "completed",
     *       "created_at": "2024-01-15 10:30:00"
     *     }
     *   ],
     *   "links": {...},
     *   "meta": {...}
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWithdrawals(Request $request, $type = 'coin')
    {
        $withdrawalRepository = new WithdrawalRepository();

        $withdrawals = Withdrawal::collection($withdrawalRepository->getReportUser(auth()->user(), false))->response()->getData(true);

        return response()->json($withdrawals);
    }

    /**
     * Get Fiat Withdrawals
     *
     * Retrieves fiat currency withdrawal history for the authenticated user.
     * Includes withdrawals to bank accounts and other fiat payment methods.
     *
     * @operationId getFiatWithdrawals
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": "550e8400-e29b-41d4-a716-446655440000",
     *       "currency": "USD",
     *       "amount": "500.00",
     *       "fee": "5.00",
     *       "net_amount": "495.00",
     *       "payment_method": "bank_transfer",
     *       "bank_details": "****1234",
     *       "status": "completed",
     *       "created_at": "2024-01-15 10:30:00"
     *     }
     *   ],
     *   "links": {...},
     *   "meta": {...}
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFiatWithdrawals(Request $request)
    {
        $withdrawalRepository = new FiatWithdrawalRepository();

        $withdrawals = FiatWithdrawal::collection($withdrawalRepository->getReportUser(auth()->user(), false))->response()->getData(true);

        return response()->json($withdrawals);
    }

    /**
     * Withdraw Cryptocurrency
     *
     * Initiates a cryptocurrency withdrawal to an external address.
     * Withdrawal will be processed after security verification.
     *
     * @operationId withdrawCrypto
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('symbol', description: 'The currency symbol to withdraw', required: true, type: 'string', example: 'BTC')]
    #[BodyParameter('network', description: 'The network ID to use for withdrawal', required: true, type: 'integer', example: 1)]
    #[BodyParameter('address', description: 'The destination wallet address', required: true, type: 'string', example: 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh')]
    #[BodyParameter('amount', description: 'The amount to withdraw', required: true, type: 'string', example: '0.5')]
    #[BodyParameter('payment_id', description: 'Optional payment ID/memo for certain networks', type: 'string', example: '12345')]
public function withdraw(WithdrawRequest $request)
{
    $user = $request->user();

    if (!$user || !$user->tokenCan('withdraw')) {
        return response()->json([
            'message' => 'Unauthorized'
        ], STATUS_FORBIDDEN);
    }

    /*
     * 提现前必须完成 KYC。
     */
    if (!$this->hasCompletedKyc($user)) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete KYC verification before withdrawing.',
        ], 422);
    }

    /**
     * Freeze withdrawal check
     * users.withdrawal_disabled = true means this user cannot withdraw
     */
    if ((bool) ($user->withdrawal_disabled ?? false)) {
        return response()->json([
            'success' => false,
            'message' => 'Your withdrawal function has been temporarily frozen. Please contact customer service.',
        ], 422);
    }

    /**
     * 內部提現：
     * recipient_type=uid 使用账户 UID；旧客户端继续使用原收款码。
     */
    if ($request->boolean('internal_transfer') || $request->get('withdraw_type') === 'internal') {
        if (!$this->canUseInternalWithdraw($user)) {
            return response()->json([
                'success' => false,
                'message' => __('Your VIP level is not eligible for internal withdrawal'),
            ], 422);
        }

        return $this->internalWithdraw($request);
    }

    $symbol = request()->get('symbol', null);
    $network = request()->get('network', null);
    $address = request()->get('address', null);
    $amount = request()->get('amount', null);
    $payment_id = request()->get('payment_id', null);

    $result = (new WalletService())->withdrawCrypto(
        $symbol,
        $address,
        $amount,
        $user->getAuthIdentifier(),
        $network,
        $payment_id
    );

    return response()->json([
        'result' => $result
    ]);
}

protected function hasCompletedKyc($user): bool
{
    if (!$user) {
        return false;
    }

    /*
     * 如果 users 表里本身有 KYC 标记，优先兼容。
     */
    foreach (['kyc_verified', 'is_kyc_verified', 'kyc_approved'] as $field) {
        if (isset($user->{$field}) && (bool) $user->{$field}) {
            return true;
        }
    }

    if (!empty($user->kyc_verified_at)) {
        return true;
    }

    if (isset($user->kyc_status)) {
        $userKycStatus = strtolower(trim((string) $user->kyc_status));

        if (in_array($userKycStatus, [
            'approved',
            'verified',
            'accepted',
            'completed',
            'complete',
            'success',
        ], true)) {
            return true;
        }
    }

    /*
     * 查询 kyc_documents 表。
     */
    if (!$this->hasTableCached('kyc_documents')) {
        return false;
    }

    if (!$this->hasColumnCached('kyc_documents', 'user_id')) {
        return false;
    }

    $query = \Illuminate\Support\Facades\DB::table('kyc_documents')
        ->where('user_id', $user->getAuthIdentifier());

    /*
     * 如果有 approved_at，通过 approved_at 判断。
     */
    if ($this->hasColumnCached('kyc_documents', 'approved_at')) {
        if ((clone $query)->whereNotNull('approved_at')->exists()) {
            return true;
        }
    }

    if (!$this->hasColumnCached('kyc_documents', 'status')) {
        return false;
    }

    $statusType = '';

    try {
        $statusType = \Illuminate\Support\Facades\Schema::getColumnType('kyc_documents', 'status');
    } catch (\Throwable $e) {
        $statusType = '';
    }

    $numericStatusColumn = in_array($statusType, [
        'integer',
        'bigint',
        'smallint',
        'tinyint',
        'boolean',
    ], true);

    /*
     * 如果你的 KYC 通过状态是数字，这里默认兼容 1 / 2。
     * 如果你的系统里通过状态只等于 1 或只等于 2，可以按实际情况删掉另一个。
     */
    if ($numericStatusColumn) {
        return (clone $query)
            ->whereIn('status', [1, 2])
            ->exists();
    }

    return (clone $query)
        ->whereIn(
            \Illuminate\Support\Facades\DB::raw('LOWER(status)'),
            [
                'approved',
                'verified',
                'accepted',
                'completed',
                'complete',
                'success',
            ]
        )
        ->exists();
}

protected function canUseInternalWithdraw($user): bool
{
    $minVip = (int) \Setting::get('wallet.internal_withdraw_min_vip', 0);
    $userVip = (int) ($user->vip ?? 0);

    return $userVip >= $minVip;
}

protected function internalWithdraw(WithdrawRequest $request)
{
    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
        'symbol' => ['required', 'string'],
        'internal_uid' => ['required', 'string', 'max:100'],
        'amount' => ['required', 'numeric', 'gt:0'],
    ], [
        'symbol.required' => __('Currency is required'),
        'internal_uid.required' => __('Please enter recipient UID'),
        'internal_uid.string' => __('Recipient UID is invalid'),
        'internal_uid.max' => __('Recipient UID is invalid'),
        'amount.required' => __('Please enter withdrawal amount'),
        'amount.numeric' => __('Invalid amount'),
        'amount.gt' => __('Invalid amount'),
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }

    $sender = $request->user();
    $recipientReferralCode = trim((string) $request->get('internal_uid'));
    $amount = $request->get('amount');
    $symbol = strtoupper(trim((string) $request->get('symbol')));

    $recipient = app(\App\Services\Wallet\InternalTransferRecipient::class)->resolve(
        $sender,$recipientReferralCode,(string)$request->input('recipient_type','legacy_code'));
    $recipientReferralCode = (string)$recipient->referral_code;

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->where('symbol', $symbol)
        ->first();

    if (!$currency) {
        return response()->json([
            'message' => __('Currency not found')
        ], 422);
    }

    if (isset($currency->withdraw_status) && !$currency->withdraw_status) {
        return response()->json([
            'message' => __('Withdrawal Suspended')
        ], 422);
    }

    if (isset($currency->min_withdraw) && math_compare($amount, $currency->min_withdraw) < 0) {
        return response()->json([
            'message' => __('Amount is less than minimum withdrawal amount')
        ], 422);
    }

    if (
        isset($currency->max_withdraw) &&
        math_compare($currency->max_withdraw, 0) > 0 &&
        math_compare($amount, $currency->max_withdraw) > 0
    ) {
        return response()->json([
            'message' => __('Amount is greater than maximum withdrawal amount')
        ], 422);
    }

    $walletService = new WalletService();

    return \Illuminate\Support\Facades\DB::transaction(function () use (
        $sender,
        $recipient,
        $recipientReferralCode,
        $currency,
        $amount,
        $walletService
    ) {
        $senderWallet = $walletService->getWalletByCurrency($sender->id, $currency->id);
        $recipientWallet = $walletService->getWalletByCurrency($recipient->id, $currency->id);

        if (!$senderWallet) {
            return response()->json([
                'message' => __('Wallet not found')
            ], 422);
        }

        if (!$recipientWallet) {
            return response()->json([
                'message' => __('Recipient wallet not found')
            ], 422);
        }

        if (math_compare($senderWallet->balance_in_wallet, $amount) < 0) {
            return response()->json([
                'message' => __('Insufficient balance')
            ], 422);
        }

        $internalId = generate_uuid();
        $depositId = generate_uuid();
        $txn = 'internal-' . $internalId;

        /**
         * 扣除发起人的资金账户
         */
        $walletService->decrease($senderWallet, $amount, 'wallet');

        /**
         * 增加收款人的资金账户
         */
        $walletService->increase($recipientWallet, $amount, 'wallet');

        $rawData = [
            'type' => 'internal_transfer',
            'from_user_id' => $sender->id,
            'from_referral_code' => $sender->referral_code ?? null,
            'to_user_id' => $recipient->id,
            'to_referral_code' => $recipientReferralCode,
            'currency_id' => $currency->id,
            'symbol' => $currency->symbol,
            'amount' => $amount,
            'completed_at' => now()->toDateTimeString(),
        ];

        /**
         * 发起人提现记录
         */
        \Illuminate\Support\Facades\DB::table('withdrawals')->insert([
            'withdrawal_id' => $internalId,
            'txn' => $txn,
            'type' => 'coin',
            'currency_id' => $currency->id,
            'source_id' => 'internal',
            'network_id' => 0,
            'amount' => $amount,
            'fee' => 0,
            'address' => 'UID:' . \App\Services\Wallet\InternalTransferRecipient::uid((int)$recipient->id),
            'payment_id' => null,
            'user_id' => $sender->id,
            'confirms' => 0,
            'status' => 'confirmed_provider',
            'rejected_reason' => null,
            'initial_raw' => json_encode($rawData, JSON_UNESCAPED_UNICODE),
            'raw' => json_encode($rawData, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
            'inusd' => 0,
            'extra_status' => 'internal_completed',
            'internal_id' => (string) $recipientReferralCode,
        ]);

        /**
         * 收款人充值记录
         * 对方充值列表会显示这笔内部转账入账。
         */
        \Illuminate\Support\Facades\DB::table('deposits')->insert([
            'deposit_id' => $depositId,
            'txn' => $txn,
            'type' => 'coin',
            'currency_id' => $currency->id,
            'source_id' => 'internal',
            'network_id' => 0,
            'amount' => $amount,
            'network_fee' => 0,
            'system_fee' => 0,
            'address' => 'UID:' . \App\Services\Wallet\InternalTransferRecipient::uid((int)$sender->id),
            'payment_id' => null,
            'user_id' => $recipient->id,
            'confirms' => 0,
            'status' => 'confirmed',
            'initial_raw' => json_encode(array_merge($rawData, [
                'record_type' => 'internal_deposit',
                'reference_withdrawal_id' => $internalId,
            ]), JSON_UNESCAPED_UNICODE),
            'raw' => json_encode(array_merge($rawData, [
                'record_type' => 'internal_deposit',
                'reference_withdrawal_id' => $internalId,
            ]), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
            'wallet_transfer_status' => 'confirmed',
            'full_amount' => $amount,
            'internal_id' => (string) ($sender->referral_code ?? $sender->id),
            'awarded' => false,
        ]);

        event(new \App\Events\WalletUpdated($senderWallet));
        event(new \App\Events\WalletUpdated($recipientWallet));

        return response()->json([
            'success' => true,
            'message' => __('Internal withdrawal completed'),
            'result' => [
                'type' => 'internal',
                'status' => 'confirmed_provider',
                'amount' => $amount,
                'fee' => 0,
                'recipient_user_id' => $recipient->id,
                'recipient_uid' => \App\Services\Wallet\InternalTransferRecipient::uid((int)$recipient->id),
                'txn' => $txn,
            ],
        ]);
    }, DB_REPEAT_AFTER_DEADLOCK);
}

    /**
     * Validate Stripe Payment
     *
     * Validates payment form data before initiating Stripe payment.
     *
     * @operationId validateStripePayment
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('currency_id', description: 'The currency ID to deposit', required: true, type: 'integer', example: 2)]
    #[BodyParameter('amount', description: 'The amount to deposit', required: true, type: 'string', example: '100.00')]
    public function stripePaymentValidate(StripePaymentFormRequest $request) {
        return response()->json(['success' => true]);
    }

    /**
     * Initialize Stripe Payment
     *
     * Initializes a Stripe payment intent for fiat currency deposits.
     * Returns client secret for completing payment on frontend.
     *
     * @operationId initStripePayment
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('currency_id', description: 'The currency ID to deposit', required: true, type: 'integer', example: 2)]
    #[BodyParameter('amount', description: 'The amount to deposit', required: true, type: 'string', example: '100.00')]
    public function stripePayment(StripePaymentFormRequest $request) {

        Stripe::setApiKey(setting('stripe.secret_key'));

        $baseCurrency = mb_strtolower(setting('stripe.currency', 'usd'));

        try {

            $zeroCurrencies = config('stripe.zero_currencies');

            $currency = (new CurrencyRepository())->get($request->get('currency_id'));

            $amount = $request->get('amount');

            $actualAmount = math_multiply($amount, $currency->cc_exchange_rate);

            if(!in_array(mb_strtoupper($baseCurrency), $zeroCurrencies)) {
                $actualAmount = math_multiply($actualAmount, 100);
            }

            $paymentIntent = PaymentIntent::create([
                'amount' => intval($actualAmount),
                'currency' => $baseCurrency,
            ]);

            $intent = new StripeModel();
            $intent->intent_id = $paymentIntent->id;
            $intent->status = 'pending';
            $intent->currency_id = $request->get('currency_id');
            $intent->user_id = auth()->user()->getAuthIdentifier();
            $intent->save();

            $output = [
                'clientSecret' => $paymentIntent->client_secret,
            ];

            return response()->json($output);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get Available Networks
     *
     * Retrieves all available blockchain networks for deposits and withdrawals.
     * Each network includes its name and supported features.
     *
     * @operationId getAvailableNetworks
     *
     * @response 200 scenario="Success" [
     *   {
     *     "id": 1,
     *     "name": "Bitcoin",
     *     "symbol": "BTC",
     *     "deposit_enabled": true,
     *     "withdrawal_enabled": true
     *   },
     *   {
     *     "id": 2,
     *     "name": "Ethereum",
     *     "symbol": "ETH",
     *     "deposit_enabled": true,
     *     "withdrawal_enabled": true
     *   }
     * ]
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNetworks() {

        $networks = Network::where('type', 'coin')->where('id', '!=', NETWORK_COINPAYMENTS)->get();

        $withdrawals = NetworkLite::collection($networks);

        return response()->json($withdrawals);
    }

    /**
     * Load Currency Networks
     *
     * Retrieves available networks for a specific currency.
     *
     * @operationId loadCurrencyNetworks
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('symbol', description: 'The currency symbol', required: true, type: 'string', example: 'USDT')]
    public function loadNetworks(Request $request)
    {
        $symbol = $request->get('symbol');

        if(!$symbol) {
            return response()->json(['success' => false]);
        }

        $currency = (new CurrencyService())->getCurrencyBySymbol($symbol, 'coin', true, ['networks', 'file']);

        if(!$currency) {
            return response()->json(['success' => false]);
        }

        if ($request->boolean('include_unavailable')) {
            $purpose = $request->get('purpose') === 'withdraw' ? 'withdraw' : 'deposit';
            $rows = app(\App\Services\Wallet\NetworkAvailability::class)->forCurrency($currency, $purpose, auth()->id());
            return response()->json(['networks' => $rows, 'purpose' => $purpose])->header('Cache-Control', 'private, no-store');
        }

        $networks = [];
        $depositOnly = $request->get('purpose') === 'deposit';
        $withdrawOnly = $request->get('purpose') === 'withdraw';
        $disabled = $currency->disabled_deposit_networks ?? [];
        if (is_string($disabled)) $disabled = json_decode($disabled, true);
        $disabled = is_array($disabled) ? array_map('intval', $disabled) : [];

        foreach($currency->networks as $network) {
            if (\App\Services\Wallet\AssetNetworkOptions::nativeMismatch($currency, $network->slug)) continue;
            if ($depositOnly && app(\App\Services\Deposit\DepositChannelPolicy::class)->error((int)$currency->id,(int)$network->id, true, auth()->id())) continue;
            if ($withdrawOnly && app(\App\Services\Wallet\WithdrawalNetworkPolicy::class)->error((int)$currency->id, (int)$network->id)) continue;
            if ($depositOnly && (!filter_var($currency->deposit_status, FILTER_VALIDATE_BOOLEAN)
                || !filter_var($network->deposit_status, FILTER_VALIDATE_BOOLEAN)
                || in_array((int) $network->id, $disabled, true))) {
                continue;
            }

            if($network->id == NETWORK_COINPAYMENTS) {
                $network->name = $currency->coinpayments_description;
            }

            $networks[$network->id] = $network->name;
        }

        return response()->json($networks);
    }

    /**
     * Get Deposit Info
     *
     * Retrieves deposit information for a specific cryptocurrency.
     * Includes available networks, minimum deposit amounts, and currency details.
     *
     * @operationId getDepositInfo
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('symbol', description: 'The currency symbol', required: true, type: 'string', example: 'BTC')]
    public function getDepositInfo(Request $request) {

        $symbol = $request->get('symbol', false);

        if(!$symbol) {
            return response()->json(['success' => false])->setStatusCode(STATUS_NOT_FOUND);
        }

        $currency = (new CurrencyService())->getCurrencyBySymbol($symbol, 'coin', true, ['networks', 'file']);

        if(!$currency) {
            return response()->json(['success' => false])->setStatusCode(STATUS_NOT_FOUND);
        }

        $networks = [];

        foreach($currency->networks as $network) {

            if($network->id == NETWORK_COINPAYMENTS) {
                $network->name = $currency->coinpayments_description;
            }

            $networks[$network->id] = $network->name;
        }

        return response()->json([
            'symbol' => $symbol,
            'currency' => new Currency($currency),
            'networks' => $networks
        ]);

    }

    /**
     * Get Withdrawal Info
     *
     * Retrieves withdrawal information for a specific cryptocurrency.
     * Includes available networks, fees, limits, and remaining daily limit.
     *
     * @operationId getWithdrawInfo
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('symbol', description: 'The currency symbol', required: true, type: 'string', example: 'BTC')]
    public function getWithdrawInfo(Request $request) {

        $symbol = $request->get('symbol', false);

        if(!$symbol) {
            return response()->json(['success' => false])->setStatusCode(STATUS_NOT_FOUND);
        }

        $currency = (new CurrencyService())->getCurrencyBySymbol($symbol, 'coin', true, ['networks', 'file']);

        if(!$currency) {
            return response()->json(['success' => false])->setStatusCode(STATUS_NOT_FOUND);
        }

        $limit =  (new CurrencyService())->getDailyAvailableWithdrawal($currency, auth()->user());

        $networks = [];

        foreach($currency->networks as $network) {

            if($network->id == NETWORK_COINPAYMENTS) {
                $network->name = $currency->coinpayments_description;
            }

            $networks[$network->id] = $network->name;
        }

        return response()->json([
            'limit' => $limit,
            'symbol' => $symbol,
            'currency' => new Currency($currency),
            'networks' => $networks
        ]);

        return response()->json($networks);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    /**
     * Transfer Between Accounts
     *
     * Transfers funds between Funding (wallet) and Trading accounts.
     * A commission may apply when transferring from Trading to Funding.
     *
     * **Transfer Directions:**
     * - `to_trade`: Transfer from Funding to Trading account
     * - `to_funding`: Transfer from Trading to Funding account (may incur commission)
     *
     * @operationId transferBetweenAccounts
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[BodyParameter('currency_id', description: 'The currency ID to transfer', required: true, type: 'integer', example: 1)]
    #[BodyParameter('amount', description: 'The amount to transfer', required: true, type: 'string', example: '100.00')]
    #[BodyParameter('direction', description: 'Transfer direction: "to_trade" or "to_funding"', type: 'string', example: 'to_trade')]
   


public function transfer(TransferRequest $request)
{
    $currencyId = $request->get('currency_id');
    $amount = $this->safeDecimal($request->get('amount'));
    $direction = $request->get('direction', 'to_trade');

    $wallet = $this->walletService->getWalletByCurrency(
        $request->user()->getAuthIdentifier(),
        $currencyId
    );

    if (!$wallet) {
        return response()->json([
            'success' => false,
            'message' => __('Wallet not found')
        ], 404);
    }

    $map = [
        'to_trade'     => ['from' => 'wallet', 'to' => 'trade'],
        'to_funding'   => ['from' => 'trade',  'to' => 'wallet'],
        'to_lc'        => ['from' => 'wallet', 'to' => 'lc'],
        'from_lc'      => ['from' => 'lc',     'to' => 'wallet'],
        'trade_to_lc'  => ['from' => 'trade',  'to' => 'lc'],
        'lc_to_trade'  => ['from' => 'lc',     'to' => 'trade'],
    ];

    if (!isset($map[$direction])) {
        return response()->json([
            'success' => false,
            'message' => __('Invalid transfer direction')
        ], 422);
    }

    $fromField = $map[$direction]['from'];
    $toField = $map[$direction]['to'];

    if (math_compare($amount, 0) <= 0) {
        return response()->json([
            'success' => false,
            'message' => __('Invalid amount')
        ], 422);
    }

    /**
     * 真实账户字段。
     */
    $realFieldMap = [
        'wallet' => 'balance_in_wallet',
        'trade'  => 'balance_in_trade',
        'lc'     => 'balance_in_lc',
    ];

    /**
     * 虚拟账户字段。
     *
     * 只对 Funding <-> Trade 转账启用虚拟账户。
     * LC 不混入虚拟账户，避免虚拟资金流入真实理财字段。
     */
    $virtualFieldMap = [
        'wallet' => 'balance_in_virtual_wallet',
        'trade'  => 'balance_in_virtual_trade',
    ];

    $fromBalanceField = $realFieldMap[$fromField] ?? null;
    $toBalanceField = $realFieldMap[$toField] ?? null;
    $usingVirtualTransfer = false;

    if (!$fromBalanceField || !$toBalanceField) {
        return response()->json([
            'success' => false,
            'message' => __('Invalid transfer direction')
        ], 422);
    }

    /**
     * Funding <-> Trade 转账支持虚拟余额。
     *
     * 只有虚拟余额足够覆盖本次金额时，才允许整笔走虚拟余额。
     * 如果页面 MAX 使用的是真实余额，后端会继续走真实余额，避免误报余额不足。
     */
    if (
        in_array($fromField, ['wallet', 'trade'], true) &&
        in_array($toField, ['wallet', 'trade'], true)
    ) {
        $virtualFromField = $virtualFieldMap[$fromField] ?? null;
        $virtualToField = $virtualFieldMap[$toField] ?? null;

        if (
            $virtualFromField &&
            $virtualToField &&
            $this->transferWalletColumnExists($virtualFromField) &&
            $this->transferWalletColumnExists($virtualToField)
        ) {
            $virtualAvailable = $this->safeDecimal($wallet->{$virtualFromField} ?? 0);
            $realAvailable = $this->safeDecimal($wallet->{$fromBalanceField} ?? 0);
            $requestedVirtualSource = $request->get('virtual_balance_source');
            $requestedVirtualTransfer = filter_var($request->get('use_virtual_wallet'), FILTER_VALIDATE_BOOLEAN);

            /*
             * 不能只要虚拟余额大于 0 就强制整笔走虚拟余额。
             * 否则页面点击“MAX”填入真实余额时，如果虚拟余额更小，会误报余额不足。
             */
            if (
                math_compare($virtualAvailable, 0) > 0 &&
                math_compare($virtualAvailable, $amount) >= 0 &&
                (
                    math_compare($realAvailable, $amount) < 0 ||
                    ($requestedVirtualTransfer && $requestedVirtualSource === $virtualFromField)
                )
            ) {
                $usingVirtualTransfer = true;
                $fromBalanceField = $virtualFromField;
                $toBalanceField = $virtualToField;
            }
        }
    }

    $available = $this->safeDecimal($wallet->{$fromBalanceField} ?? 0);

    if (math_compare($available, $amount) < 0) {
        return response()->json([
            'success' => false,
            'message' => __('Insufficient balance')
        ], 422);
    }

    $user = auth()->user()->fresh();

    /**
     * 识别 VIP 等级
     *
     * 兼容字段：
     * vip / vip_level / vipLevel / member_level / membership_level / level
     */
    $vipLevel = 0;

    $vipCandidates = [
        $user->vip ?? null,
        $user->vip_level ?? null,
        $user->vipLevel ?? null,
        $user->member_level ?? null,
        $user->membership_level ?? null,
        $user->level ?? null,
    ];

    foreach ($vipCandidates as $vipValue) {
        if ($vipValue === null || $vipValue === '') {
            continue;
        }

        if (is_numeric($vipValue)) {
            $parsedVipLevel = (int) $vipValue;
        } else {
            preg_match('/\d+/', (string) $vipValue, $matches);
            $parsedVipLevel = isset($matches[0]) ? (int) $matches[0] : 0;
        }

        if ($parsedVipLevel >= 1 && $parsedVipLevel <= 8) {
            $vipLevel = $parsedVipLevel;
            break;
        }
    }

    /**
     * 理财收益 VIP 加成比例
     */
    $vipYieldBoostMap = [
        1 => 10,
        2 => 20,
        3 => 30,
        4 => 40,
        5 => 50,
        6 => 60,
        7 => 70,
        8 => 80,
    ];

    $vipYieldBoostRate = (float) ($vipYieldBoostMap[$vipLevel] ?? 0);

    /**
     * 默认到账金额。
     */
    $credited = $amount;

    /**
     * LC 收益拆分。
     */
    $lcPrincipal = '0';
    $lcBaseProfit = '0';
    $lcVipProfit = '0';
    $lcTotalProfit = '0';
    $lcElapsedDays = 0;
    $lcEarningDays = 0;
    $lcInvestDays = 0;
    $lcRate = 0;

    /**
     * LC 转出方向。
     */
    $isLcOut = in_array($direction, ['from_lc', 'lc_to_trade']);

    if ($isLcOut) {
        $investDays = (int) ($user->invest_funding_time ?? 30);

        if ($investDays <= 0) {
            $investDays = 30;
        }

        $savedInvestmentType = $user->auto_invest_type ?: 'fixed';

        if (!in_array($savedInvestmentType, ['flexible', 'fixed'])) {
            $savedInvestmentType = 'fixed';
        }

        $rateMap = [
            30  => (float) Setting::get('trade.lc30', 0),
            90  => (float) Setting::get('trade.lc90', 0),
            180 => (float) Setting::get('trade.lc180', 0),
        ];

        if (!isset($rateMap[$investDays]) || $investDays <= 0) {
            return response()->json([
                'success' => false,
                'message' => __('Invalid invest funding time')
            ], 422);
        }

        if (empty($wallet->lc_addtime)) {
            return response()->json([
                'success' => false,
                'message' => __('Fixed assets cannot be transferred because the lock start time is missing.')
            ], 422);
        }

        $rate = $rateMap[$investDays];

        $startTime = Carbon::parse($wallet->lc_addtime);
        $nowTime = Carbon::now();
        $maturityTime = $startTime->copy()->addDays($investDays);

        if ($savedInvestmentType === 'fixed' && $nowTime->lt($maturityTime)) {
            $remainingSeconds = max(0, $nowTime->diffInSeconds($maturityTime, false));

            return response()->json([
                'success' => false,
                'message' => __('Fixed assets cannot be transferred before maturity.'),
                'data' => [
                    'investment_type' => 'fixed',
                    'invest_days' => $investDays,
                    'start_at' => $startTime->format('Y-m-d H:i:s'),
                    'maturity_at' => $maturityTime->format('Y-m-d H:i:s'),
                    'remaining_seconds' => $remainingSeconds,
                    'remaining_days' => (int) ceil($remainingSeconds / 86400),
                ],
            ], 422);
        }

        $startDate = Carbon::parse($wallet->lc_addtime)->startOfDay();
        $today = Carbon::now()->startOfDay();

        $elapsedDays = (int) floor($startDate->diffInDays($today));
        $earningDays = min(max(0, $elapsedDays), $investDays);

        $principal = (float) $amount;
        $baseProfit = 0;

        if ($savedInvestmentType === 'flexible') {
            if ($elapsedDays >= $investDays) {
                $baseProfit = $principal * ($rate / 100);
                $earningDays = $investDays;
            } else {
                $baseProfit = $principal * (((($rate / $investDays) / 100) / 2) * $earningDays);
            }
        } else {
            $baseProfit = $principal * ($rate / 100);
            $earningDays = $investDays;
        }

        $vipProfit = $baseProfit * ($vipYieldBoostRate / 100);
        $totalProfit = $baseProfit + $vipProfit;

        $credited = $principal + $totalProfit;
        $credited = math_formatter($credited, 18, '.', '');

        $lcPrincipal = math_formatter($principal, 18, '.', '');
        $lcBaseProfit = math_formatter($baseProfit, 18, '.', '');
        $lcVipProfit = math_formatter($vipProfit, 18, '.', '');
        $lcTotalProfit = math_formatter($totalProfit, 18, '.', '');
        $lcElapsedDays = $elapsedDays;
        $lcEarningDays = $earningDays;
        $lcInvestDays = $investDays;
        $lcRate = $rate;
    }

    /**
     * 手续费只在 trade -> funding 方向收取。
     * 虚拟账户 trade -> funding 同样收取。
     */
    $isTransferCommissionDirection = $direction === 'to_funding';

    $percent = $isTransferCommissionDirection
        ? Setting::get('trade.transfer_commission_percent', 0)
        : 0;

    $commission = '0';
    $creditedBeforeRefund = $credited;

    $discountRate = $this->getTransferFeeDiscount($user);
    $refundRate = $this->getRefundRateByDiscount($discountRate);
    $refundAmount = '0';
    $creditedAfterRefund = $credited;

    if (math_compare($percent, 0) > 0) {
        $commission = math_percentage($credited, $percent);
        $credited = math_sub($credited, $commission);

        if (math_compare($credited, 0) <= 0) {
            return response()->json([
                'success' => false,
                'message' => __('Amount after commission must be greater than 0')
            ], 422);
        }

        $creditedBeforeRefund = $credited;

        if (math_compare($commission, 0) > 0 && math_compare($refundRate, 0) > 0) {
            $refundAmount = $this->safeDecimal(
                math_formatter(math_percentage($commission, $refundRate), 8)
            );

            if (math_compare($refundAmount, 0) > 0) {
                $creditedAfterRefund = math_sum($credited, $refundAmount);
            } else {
                $refundAmount = '0';
                $creditedAfterRefund = $credited;
            }
        } else {
            $creditedAfterRefund = $credited;
        }
    }

    $actualCommission = math_sub($commission, $refundAmount);

    if (math_compare($actualCommission, 0) < 0) {
        $actualCommission = '0';
    }

    $canStoreTransferRecord = $this->hasTableCached('wallet_transfer_records');
    $transferCommissionId = null;
    $transferRecordId = null;

    DB::transaction(function () use (
        $wallet,
        $amount,
        $credited,
        $creditedBeforeRefund,
        $creditedAfterRefund,
        $fromField,
        $toField,
        $fromBalanceField,
        $toBalanceField,
        $usingVirtualTransfer,
        $percent,
        $commission,
        $refundAmount,
        $refundRate,
        $discountRate,
        $vipLevel,
        $direction,
        $actualCommission,
        $canStoreTransferRecord,
        &$transferCommissionId,
        &$transferRecordId
    ) {
        /**
         * 扣来源账户。
         *
         * 如果来源虚拟余额 > 0：
         *      只扣虚拟字段。
         *
         * 如果来源虚拟余额 = 0：
         *      扣真实字段。
         */
        $this->decreaseTransferWalletField($wallet, $fromBalanceField, $amount);

        /**
         * 增加目标账户，到账金额已经扣过原始手续费。
         */
        $this->increaseTransferWalletField($wallet, $toBalanceField, $credited);

        /**
         * 手续费减免部分返还到本次划转的目标账户。
         * 虚拟账户划转时返还到虚拟目标字段。
         */
        if (math_compare($refundAmount, 0) > 0) {
            $this->increaseTransferWalletField($wallet, $toBalanceField, $refundAmount);
        }

        /**
         * 转入 LC 时，更新理财开始时间。
         */
        if ($toField === 'lc') {
            $wallet->lc_addtime = now();
            $wallet->save();
        }

        /**
         * LC 全部转出时，清空 lc_addtime。
         */
        if ($fromField === 'lc') {
            $wallet->refresh();

            if (math_compare($wallet->balance_in_lc, 0) <= 0) {
                $wallet->lc_addtime = null;
                $wallet->save();
            }
        }

        /**
         * 记录原始手续费。
         */
        if (math_compare($percent, 0) > 0 && math_compare($commission, 0) > 0) {
            $transferCommission = \App\Models\Wallet\TransferCommission::create([
                'user_id' => auth()->id(),
                'wallet_id' => $wallet->id,
                'currency_id' => $wallet->currency_id,
                'direction' => $direction,
                'amount' => $amount,
                'percent' => $percent,
                'commission_amount' => $commission,
                'credited_amount' => $credited,
            ]);

            $transferCommissionId = $transferCommission->id ?? null;
        }

        /**
         * 手续费减免返还记录。
         */
        if (math_compare($refundAmount, 0) > 0) {
            \Illuminate\Support\Facades\DB::table('transfer_fee_refund_records')->insert([
                'id' => generate_uuid(),
                'commission_record_id' => $transferCommissionId,
                'user_id' => auth()->id(),
                'wallet_id' => $wallet->id,
                'currency_id' => $wallet->currency_id,
                'direction' => $direction,
                'from_account' => $usingVirtualTransfer ? $fromBalanceField : $fromField,
                'to_account' => $usingVirtualTransfer ? $toBalanceField : $toField,
                'original_fee' => $this->safeDecimal($commission),
                'vip_level' => $vipLevel,
                'discount_rate' => $this->safeDecimal($discountRate, 8),
                'refund_rate' => $this->safeDecimal($refundRate, 8),
                'refund_amount' => $this->safeDecimal($refundAmount),
                'credited_before_refund' => $this->safeDecimal($creditedBeforeRefund),
                'credited_after_refund' => $this->safeDecimal($creditedAfterRefund),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($canStoreTransferRecord) {
            $transferRecord = \App\Models\Wallet\WalletTransferRecord::create([
                'user_id' => auth()->id(),
                'wallet_id' => $wallet->id,
                'currency_id' => $wallet->currency_id,
                'commission_record_id' => $transferCommissionId,
                'direction' => $direction,
                'account_type' => $usingVirtualTransfer ? 'virtual' : 'real',
                'from_account' => $fromBalanceField,
                'to_account' => $toBalanceField,
                'amount' => $this->safeDecimal($amount),
                'credited_amount' => $this->safeDecimal($creditedAfterRefund),
                'original_fee_amount' => $this->safeDecimal($commission),
                'fee_refund_amount' => $this->safeDecimal($refundAmount),
                'fee_amount' => $this->safeDecimal($actualCommission),
                'fee_percent' => $this->safeDecimal($percent, 8),
                'discount_rate' => $this->safeDecimal($discountRate, 8),
                'refund_rate' => $this->safeDecimal($refundRate, 8),
            ]);

            $transferRecordId = $transferRecord->id;
        }
    }, DB_REPEAT_AFTER_DEADLOCK);

    $wallet = $this->walletService->getWallet($wallet->id);
    $balances = $this->buildTransferWalletBalancesPayload($wallet);

    return response()->json([
        'success' => true,
        'account_type' => $usingVirtualTransfer ? 'virtual' : 'real',
        'from_balance_field' => $fromBalanceField,
        'to_balance_field' => $toBalanceField,

        'commission' => $this->safeDecimal($commission, 4),
        'commission_refund' => $this->safeDecimal($refundAmount, 4),
        'actual_commission' => $this->safeDecimal($actualCommission, 4),
        'transfer_record_id' => $transferRecordId,
        'refund_rate' => $this->safeDecimal($refundRate, 8),
        'discount_rate' => $this->safeDecimal($discountRate, 8),

        'credited' => $this->safeDecimal($creditedAfterRefund, 4),
        'credited_before_refund' => $this->safeDecimal($creditedBeforeRefund, 4),

        'lc_principal' => $this->safeDecimal($lcPrincipal, 4),
        'lc_base_profit' => $this->safeDecimal($lcBaseProfit, 4),
        'lc_vip_profit' => $this->safeDecimal($lcVipProfit, 4),
        'lc_total_profit' => $this->safeDecimal($lcTotalProfit, 4),
        'lc_elapsed_days' => $lcElapsedDays,
        'lc_earning_days' => $lcEarningDays,
        'lc_invest_days' => $lcInvestDays,
        'lc_rate' => $lcRate,
        'vip_level' => $vipLevel,
        'vip_yield_boost_rate' => $vipYieldBoostRate,

        'balances' => $balances,
    ]);
}
protected function transferWalletColumnExists(string $field): bool
{
    try {
        return $this->hasColumnCached('wallets', $field);
    } catch (\Throwable $e) {
        return false;
    }
}

protected function allowedTransferWalletFields(): array
{
    return [
        'balance_in_wallet',
        'balance_in_trade',
        'balance_in_lc',
        'balance_in_order',
        'balance_in_withdraw',
        'balance_in_virtual_wallet',
        'balance_in_virtual_trade',
        'balance_in_virtual_order',
        'balance_in_virtual_withdraw',
    ];
}

protected function assertTransferWalletField(string $field): void
{
    if (!in_array($field, $this->allowedTransferWalletFields(), true)) {
        throw new \Exception('Invalid wallet balance field');
    }

    if (!$this->transferWalletColumnExists($field)) {
        throw new \Exception('Wallet balance field does not exist: ' . $field);
    }
}

protected function decreaseTransferWalletField($wallet, string $field, $amount): void
{
    $this->assertTransferWalletField($field);

    $amount = $this->safeDecimal($amount);

    if (math_compare($amount, 0) <= 0) {
        return;
    }

    $freshWallet = \App\Models\Wallet\Wallet::query()
        ->where('id', $wallet->id)
        ->lockForUpdate()
        ->first();

    if (!$freshWallet) {
        throw new \Exception('Wallet not found');
    }

    $available = $this->safeDecimal($freshWallet->{$field} ?? 0);

    if (math_compare($available, $amount) < 0) {
        throw new \Exception('Insufficient balance');
    }

    \Illuminate\Support\Facades\DB::statement(
        "UPDATE wallets
         SET {$field} = GREATEST(COALESCE({$field}, 0) - ?, 0),
             updated_at = ?
         WHERE id = ?",
        [$amount, now(), $wallet->id]
    );

    $wallet->refresh();
}

protected function increaseTransferWalletField($wallet, string $field, $amount): void
{
    $this->assertTransferWalletField($field);

    $amount = $this->safeDecimal($amount);

    if (math_compare($amount, 0) <= 0) {
        return;
    }

    \Illuminate\Support\Facades\DB::statement(
        "UPDATE wallets
         SET {$field} = COALESCE({$field}, 0) + ?,
             updated_at = ?
         WHERE id = ?",
        [$amount, now(), $wallet->id]
    );

    $wallet->refresh();
}

protected function buildTransferWalletBalancesPayload($wallet): array
{
    return [
        'balance_in_wallet' => $wallet->balance_in_wallet ?? 0,
        'balance_in_trade' => $wallet->balance_in_trade ?? 0,
        'balance_in_lc' => $wallet->balance_in_lc ?? 0,
        'balance_in_order' => $wallet->balance_in_order ?? 0,
        'balance_in_withdraw' => $wallet->balance_in_withdraw ?? 0,

        'balance_in_virtual_wallet' => $wallet->balance_in_virtual_wallet ?? 0,
        'balance_in_virtual_trade' => $wallet->balance_in_virtual_trade ?? 0,
        'balance_in_virtual_order' => $wallet->balance_in_virtual_order ?? 0,
        'balance_in_virtual_withdraw' => $wallet->balance_in_virtual_withdraw ?? 0,

        'real_total' => math_sum(
            math_sum($wallet->balance_in_wallet ?? 0, $wallet->balance_in_trade ?? 0),
            $wallet->balance_in_lc ?? 0
        ),

        'virtual_total' => math_sum(
            $wallet->balance_in_virtual_wallet ?? 0,
            $wallet->balance_in_virtual_trade ?? 0
        ),
    ];
}
protected function getTransferFeeDiscount($user)
{
    $vip = intval($user->vip ?? 0);

    $discountMap = [
        0 => '1',
        1 => '0.9',
        2 => '0.8',
        3 => '0.65',
        4 => '0.55',
        5 => '0.45',
        6 => '0.35',
        7 => '0.25',
        8 => '0.20',
    ];

    return $discountMap[$vip] ?? '1';
}

protected function getRefundRateByDiscount($discount)
{
    $discount = $this->safeDecimal($discount, 8);

    if ($this->safeCompare($discount, 0, 8) < 0) {
        $discount = '1';
    }

    if ($this->safeCompare($discount, 1, 8) > 0) {
        $discount = '1';
    }

    $refundRate = math_multiply(math_sub('1', $discount), '100');

    if ($this->safeCompare($refundRate, 0, 8) < 0) {
        return '0';
    }

    if ($this->safeCompare($refundRate, 100, 8) > 0) {
        return '100';
    }

    return $this->safeDecimal($refundRate, 8);
}

private function safeDecimal($value, $scale = 18)
{
        return \App\Support\Decimal::normalize($value, (int)$scale);
    }

private function safeCompare($left, $right, $scale = 18)
{
    return bccomp($this->safeDecimal($left, $scale), $this->safeDecimal($right, $scale), $scale);
}

private function buildWalletBalancesPayload($wallet): array
{
    $realWalletBalance = $this->safeDecimal($wallet->balance_in_wallet ?? 0, 4);
    $realTradeBalance = $this->safeDecimal($wallet->balance_in_trade ?? 0, 4);
    $realOrderBalance = $this->safeDecimal($wallet->balance_in_order ?? 0, 4);
    $realWithdrawBalance = $this->safeDecimal($wallet->balance_in_withdraw ?? 0, 4);
    $realLcBalance = $this->safeDecimal($wallet->balance_in_lc ?? 0, 4);

    $virtualWalletBalance = $this->safeDecimal($wallet->balance_in_virtual_wallet ?? 0, 4);
    $virtualTradeBalance = $this->safeDecimal($wallet->balance_in_virtual_trade ?? 0, 4);
    $virtualOrderBalance = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0, 4);
    $virtualWithdrawBalance = $this->safeDecimal($wallet->balance_in_virtual_withdraw ?? 0, 4);
    $virtualAvailableForWithdraw = $virtualWalletBalance;

    $totalWalletBalance = $this->safeDecimal(math_sum($realWalletBalance, $virtualWalletBalance), 4);
    $totalTradeBalance = $this->safeDecimal(math_sum($realTradeBalance, $virtualTradeBalance), 4);
    $totalOrderBalance = $this->safeDecimal(math_sum($realOrderBalance, $virtualOrderBalance), 4);

    $realTotalBalance = $this->safeDecimal(math_sum(math_sum($realWalletBalance, $realTradeBalance), math_sum($realOrderBalance, $realLcBalance)), 4);
    $virtualTotalBalance = $this->safeDecimal(math_sum(math_sum($virtualWalletBalance, $virtualTradeBalance), $virtualOrderBalance), 4);
    $allTotalBalance = $this->safeDecimal(math_sum($realTotalBalance, $virtualTotalBalance), 4);

    return [
        /**
         * 真实账户余额
         */
        'real' => [
            'balance_in_wallet' => $realWalletBalance,
            'balance_in_trade' => $realTradeBalance,
            'balance_in_order' => $realOrderBalance,
            'balance_in_withdraw' => $realWithdrawBalance,
            'balance_in_lc' => $realLcBalance,
            'total_balance' => $realTotalBalance,
        ],

        /**
         * 虚拟账户余额
         */
        'virtual' => [
            'balance_in_virtual_wallet' => $virtualWalletBalance,
            'balance_in_virtual_trade' => $virtualTradeBalance,
            'balance_in_virtual_order' => $virtualOrderBalance,
            'balance_in_virtual_withdraw' => $virtualWithdrawBalance,
            'available_for_withdraw' => $virtualAvailableForWithdraw,
            'total_balance' => $virtualTotalBalance,
        ],

        /**
         * 合计余额：真实 + 虚拟
         */
        'total' => [
            'wallet' => $totalWalletBalance,
            'trade' => $totalTradeBalance,
            'order' => $totalOrderBalance,
            'real' => $realTotalBalance,
            'virtual' => $virtualTotalBalance,
            'all' => $allTotalBalance,
        ],

        /**
         * 兼容旧前端字段：默认仍然代表真实账户余额
         */
        'balance_in_wallet' => $realWalletBalance,
        'balance_in_trade' => $realTradeBalance,
        'balance_in_order' => $realOrderBalance,
        'balance_in_withdraw' => $realWithdrawBalance,
        'balance_in_lc' => $realLcBalance,

        /**
         * 新增虚拟账户字段
         */
        'balance_in_virtual_wallet' => $virtualWalletBalance,
        'balance_in_virtual_trade' => $virtualTradeBalance,
        'balance_in_virtual_order' => $virtualOrderBalance,
        'balance_in_virtual_withdraw' => $virtualWithdrawBalance,
        'virtual_available_for_withdraw' => $virtualAvailableForWithdraw,

        /**
         * 新增合计字段
         */
        'total_balance_in_wallet' => $totalWalletBalance,
        'total_balance_in_trade' => $totalTradeBalance,
        'total_balance_in_order' => $totalOrderBalance,
        'total_real_balance' => $realTotalBalance,
        'total_virtual_balance' => $virtualTotalBalance,
        'total_balance' => $allTotalBalance,
    ];
}

    /**
     * Get Currency Balance
     *
     * Retrieves the balance for a specific currency.
     * Can return either funding (account) or trading balance.
     *
     * @operationId getCurrencyBalance
     * @authenticated
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('currency', description: 'The currency ID', required: true, type: 'integer', example: 1)]
    #[QueryParameter('type', description: 'Balance type: "account" (funding) or "trade" (trading)', type: 'string', example: 'trade')]
    public function getBalance()
    {
        $walletRepository = new WalletRepository();

        $wallet = $walletRepository->getWalletByCurrency(
            auth()->user()->getAuthIdentifier(),
            request()->get('currency'),
            false
        );

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => __('Wallet not found')
            ], 422);
        }

        $type = request()->get('type', 'account');
        if (!in_array($type, ['account', 'trade', 'lc', 'order'], true)) {
            return response()->json(['success' => false, 'message' => __('Invalid account type')], 422);
        }
        $balances = $this->buildWalletBalancesPayload($wallet);

        if ($type === 'account') {
            $balance = $balances['real']['balance_in_wallet'];
            $virtualBalance = $balances['virtual']['balance_in_virtual_wallet'];
            $totalBalance = $balances['total']['wallet'];
        } elseif ($type === 'trade') {
            $balance = $balances['real']['balance_in_trade'];
            $virtualBalance = $balances['virtual']['balance_in_virtual_trade'];
            $totalBalance = $balances['total']['trade'];
        } elseif ($type === 'lc') {
            $balance = $balances['real']['balance_in_lc'];
            $virtualBalance = '0';
            $totalBalance = $balance;
        } elseif ($type === 'order') {
            $balance = $balances['real']['balance_in_order'];
            $virtualBalance = $balances['virtual']['balance_in_virtual_order'];
            $totalBalance = $balances['total']['order'];
        } else {
            $balance = '0';
            $virtualBalance = '0';
            $totalBalance = '0';
        }

        return response()->json([
            'success' => true,

            /**
             * 兼容旧前端：balance 仍然返回真实账户余额。
             */
            'balance' => $balance,

            /**
             * 当前 type 对应的虚拟账户余额。
             */
            'virtual_balance' => $virtualBalance,

            /**
             * 当前 type 对应的真实 + 虚拟合计余额。
             */
            'total_balance' => $totalBalance,

            /**
             * 全量余额。
             */
            'balances' => $balances,
        ]);
    }
}
