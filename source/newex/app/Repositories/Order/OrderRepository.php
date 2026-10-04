<?php

namespace App\Repositories\Order;

use App\Events\OrderBookUpdated;
use App\Events\WalletUpdated;
use App\Interfaces\Order\OrderRepositoryInterface;
use App\Jobs\Market\MarketCapCalculationJob;
use App\Jobs\Order\CreateOrderJob;
use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\Order\Order;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\CopyTrading\CopyTradingService;
use App\Services\Order\OrderService;
use App\Services\Performance\ReadModelCacheService;
use App\Services\Wallet\WalletService;
use Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Setting;

class OrderRepository implements OrderRepositoryInterface
{
    public
        $order,
        $market,
        $trigger_price,
        $trigger_condition,
        $walletService,
        $walletRepository,
        $orderService,
        $marketRepository,
        $orderHistoryRepository,
        $user,
        $uuid,
        $type;

    private static $schemaTableCache = [];
    private static $schemaColumnCache = [];
    private static $futuresUserVirtualCache = [];
    private static $userReferralCache = [];
    private static $marketIdByNameCache = [];

    public function __construct(
        ?WalletService $walletService = null,
        ?MarketRepository $marketRepository = null,
        ?WalletRepository $walletRepository = null,
        ?OrderHistoryRepository $orderHistoryRepository = null
    ) {
        $this->walletService = $walletService ?? new WalletService();
        $this->marketRepository = $marketRepository ?? new MarketRepository();
        $this->walletRepository = $walletRepository ?? new WalletRepository();
        $this->orderHistoryRepository = $orderHistoryRepository ?? new OrderHistoryRepository();
    }

    public function get($market, $type, $depth = 1000)
    {
        $maxDepth = 1000;

        if ($depth > $maxDepth) {
            $depth = $maxDepth;
        }

        $market = Market::whereName($market)->value('id');

        $orders = Order::groupPrice()->whereMarketId($market)->where(function ($q) {
            $q->where('settlement_domain', 'real')->orWhere(function ($legacy) {
                $legacy->whereNull('settlement_domain')->whereExists(function ($w) {
                    $w->selectRaw('1')->from('wallets')->whereColumn('wallets.user_id', 'orders.user_id')
                      ->whereRaw("wallets.currency_id = CASE WHEN orders.side = 'buy' THEN orders.quote_currency_id ELSE orders.base_currency_id END")
                      ->where('balance_in_order', '>', 0)->where('balance_in_virtual_order', '<=', 0);
                });
            });
        });

        if ($type == Order::SIDE_BUY) {
            $orders->buyLimit();
        } else {
            $orders->sellLimit();
        }

        $orders->limit($depth);
        $orders->groupBy('price');

        return $orders->get();
    }

    public function findById($uuid)
    {
        return Order::find($uuid);
    }

    public function store($futures = false)
    {
        return app(\App\Services\Order\IdempotentOrderRequest::class)->run($futures ? 'futures' : 'spot', fn () => $this->storeInternal($futures));
    }

    private function storeInternal($futures = false)
    {
        app(\App\Services\Deposit\DepositRisk::class)->assertClear((int)auth()->id());
        $isSwap = request()->get('swap', false);
        $fixedRate = false;

        if ($isSwap) {
            if (config('app.fixed_swap')) {
                $fixedRate = true;
            }
        }

        $this->setUuid();
        $this->user = Auth::user();
        $this->market = $this->marketRepository->get(request()->get('market'));

        /**
         * 下单前先确保当前用户拥有交易对两边的钱包。
         * 例如 USDC-USDT 会先检查并创建 USDC 钱包和 USDT 钱包。
         */
        if (!$futures) {
            $this->ensureWalletsForMarketBeforeOrder();
        }

        if ($futures) {
            app(\App\Services\Order\ProductTradingAvailability::class)->check($this->market, 'futures', (string)request()->get('side'));
            try {
                $result = DB::transaction(function () {
                    $orderType = request()->get('type', 'market');
                    $isMarket = $orderType === FuturesContract::TYPE_MARKET;

                    $wallet = $this->getOrCreateWalletByCurrency(
                        $this->user->id,
                        $this->market->quote_currency_id
                    );

                    if (!$wallet) {
                        throw new \Exception('Wallet create failed');
                    }

                    $priceQuote = app(\App\Services\Market\VerifiedDerivativePrice::class)->forUser($this->market, $this->user);
                    $marketPrice = math_formatter($priceQuote['price'], $this->market->quote_precision);

                    $leverage = request()->get('leverage');
                    $isLong = request()->get('side') == 'buy';

                    $feeRate = $isMarket
                        ? Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE)
                        : Setting::get('futures.maker_fee', INITIAL_FUTURES_MAKER_FEE);

                    if ($isMarket) {
                        $size = request()->get($isLong ? 'quoteQuantity' : 'quantity');

                        // 原始手续费照常扣除，不在这里做比例减免
                        $entryFee = math_percentage($size, $feeRate);

                        $netMargin = math_sub($size, $entryFee);
                        $positionValue = math_multiply($netMargin, $leverage);
                        $amount = math_divide($positionValue, $marketPrice);
                        $orderPrice = $marketPrice;
                    } else {
                        $orderPrice = request()->get('price');
                        $size = request()->get('quantity');

                        // 原始手续费照常扣除，不在这里做比例减免
                        $entryFee = math_percentage($size, $feeRate);

                        $netMargin = math_sub($size, $entryFee);
                        $positionValue = math_multiply($netMargin, $leverage);
                        $amount = math_divide($positionValue, $orderPrice);
                    }

                    $existingPosition = null;

                    if ($isMarket) {
                        $existingPosition = $this->findMergeableFuturesPosition(
                            $this->user->id,
                            $this->market->id,
                            $isLong,
                            $leverage
                        );
                    }

                    /**
                     * 新资金逻辑：
                     * 1. 开仓先使用交易账户 balance_in_trade。
                     * 2. 交易账户不足时，使用 auto_invest_orders 可用理财金额作为质押保证金。
                     * 3. 使用理财金额时只增加 used_margin，不直接扣 amount。
                     */
                    $marginSourceId = $existingPosition ? (string) $existingPosition->id : (string) $this->uuid;
                    $marginAllocation = $this->allocateFuturesMarginWithVirtualWallet(
                        $wallet,
                        $this->user->id,
                        $this->market->quote_currency_id,
                        $size,
                        $marginSourceId,
                        $isMarket ? 'active' : 'pending'
                    );

                    if (
                        !$isMarket &&
                        $this->safeCompare($marginAllocation['trade_margin_amount'], 0) > 0
                    ) {
                        if (($marginAllocation['account_type'] ?? 'real') === 'virtual') {
                            if (!$this->walletColumnExists('balance_in_virtual_order')) {
                                throw new \Exception('Virtual order balance field does not exist');
                            }

                            $this->increaseWalletField(
                                $wallet,
                                'balance_in_virtual_order',
                                $marginAllocation['trade_margin_amount']
                            );
                        } else {
                            $this->walletService->increase($wallet, $marginAllocation['trade_margin_amount'], 'order');
                        }
                    }

                    $liquidationPrice = $this->calculateFuturesLiquidationPriceByMargin(
                        $orderPrice,
                        $amount,
                        $netMargin,
                        $isLong,
                        (int) $this->market->quote_precision,
                        [
                            'entry_fee' => $entryFee,
                            'trade_margin_amount' => $marginAllocation['trade_margin_amount'],
                            'auto_invest_margin_amount' => $marginAllocation['auto_invest_margin_amount'],
                            'total_margin_amount' => $marginAllocation['total_margin_amount'],
                            'virtual_margin_amount' => ($marginAllocation['account_type'] ?? 'real') === 'virtual'
                                ? $marginAllocation['total_margin_amount']
                                : '0',
                            'is_virtual_position' => ($marginAllocation['account_type'] ?? 'real') === 'virtual',
                        ]
                    );

                    $takeProfitPrice = request()->get('take_profit_price', null);
                    $stopLossPrice = request()->get('stop_loss_price', null);

                    $formattedTakeProfitPrice = null;
                    $formattedStopLossPrice = null;

                    if ($takeProfitPrice && $takeProfitPrice > 0) {
                        $formattedTakeProfitPrice = math_formatter(
                            $takeProfitPrice,
                            $this->market->quote_precision
                        );
                    }

                    if ($stopLossPrice && $stopLossPrice > 0) {
                        $formattedStopLossPrice = math_formatter(
                            $stopLossPrice,
                            $this->market->quote_precision
                        );
                    }

                    if ($isMarket && $existingPosition) {
                        $merged = $this->mergeFuturesPosition($existingPosition, [
                            'price' => $orderPrice,
                            'quantity' => $amount,
                            'balance' => $netMargin,
                            'entry_fee' => $entryFee,
                            'take_profit_price' => $formattedTakeProfitPrice,
                            'stop_loss_price' => $formattedStopLossPrice,
                            'trade_margin_amount' => $marginAllocation['trade_margin_amount'],
                            'auto_invest_margin_amount' => $marginAllocation['auto_invest_margin_amount'],
                            'total_margin_amount' => $marginAllocation['total_margin_amount'],
                            'referral_balance_domain' => ($this->user->is_xn || $this->user->is_xm) ? 'virtual' : ($marginAllocation['account_type'] ?? 'unknown'),
                        ]);

                        if (($formattedTakeProfitPrice || $formattedStopLossPrice) && $merged) {
                            $this->validateTPSLForMarketOrder(
                                $merged,
                                $merged->price,
                                $merged->is_long
                            );

                            $merged->save();
                        }

                        if ($merged) {
                            $this->addFuturesReferralTransactions($merged, $entryFee, 'entry', (string)$this->uuid, $marginAllocation['account_type'] ?? 'real');

                            // 手续费减免不直接少扣，通过返还入账并写记录
                            $this->refundFuturesFeeToUser($merged, $entryFee, 'entry', $wallet);
                        }

                        DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

                        return $merged->id;
                    }

                    $future = new FuturesContract();
                    $future->id = $this->uuid;
                    $future->opening_price_source = $priceQuote['source'];
                    $future->referral_balance_domain = ($this->user->is_xn || $this->user->is_xm) ? 'virtual' : ($marginAllocation['account_type'] ?? 'unknown');
                    $future->market_id = $this->market->id;
                    $future->user_id = $this->user->id;
                    $future->price = $orderPrice;
                    $future->quantity = $amount;
                    $future->liquidation_price = $liquidationPrice;
                    $future->balance = $netMargin;
                    $future->trade_margin_amount = $marginAllocation['trade_margin_amount'];
                    $future->auto_invest_margin_amount = $marginAllocation['auto_invest_margin_amount'];
                    $future->total_margin_amount = $marginAllocation['total_margin_amount'];
                    $future->leverage = $leverage;
                    $future->is_long = $isLong;
                    $future->base_currency_id = $this->market->base_currency_id;
                    $future->quote_currency_id = $this->market->quote_currency_id;
                    $future->type = $orderType;
                    $future->entry_fee = $entryFee;
                    $future->fee_rate = $feeRate;

                    if ($formattedTakeProfitPrice) {
                        $future->take_profit_price = $formattedTakeProfitPrice;
                    }

                    if ($formattedStopLossPrice) {
                        $future->stop_loss_price = $formattedStopLossPrice;
                    }

                    if ($isMarket) {
                        $future->status = 'active';
                        $future->activated_at = now();

                        if (
                            ($formattedTakeProfitPrice && $formattedTakeProfitPrice > 0) ||
                            ($formattedStopLossPrice && $formattedStopLossPrice > 0)
                        ) {
                            $this->validateTPSLForMarketOrder($future, $orderPrice, $isLong);
                        }
                    } else {
                        $future->status = 'pending';
                    }

                    $future->save();

                    if ($isMarket) {
                        $this->addFuturesReferralTransactions($future, $entryFee, 'entry', (string)$this->uuid, $marginAllocation['account_type'] ?? 'real');

                        // 市价单开仓立即扣费，所以这里立即返还减免部分
                        $this->refundFuturesFeeToUser($future, $entryFee, 'entry', $wallet);
                    }

                    DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

                    if (!$isMarket) {
                        $this->processFuturesLimitOrder($future);
                    }

                    return $future->id;
                }, DB_REPEAT_AFTER_DEADLOCK);

                $this->dispatchCopyTradingOpen($result);

                return $result;
            } catch (\Throwable $e) {
                Log::error($e);
                throw $e;
            }
        }

        try {
            return DB::transaction(function () use ($fixedRate) {
                DB::statement('SELECT pg_advisory_xact_lock(8192026, ?)', [(int)$this->market->id]);
                $this->market->refresh();
                if (!$this->market->status || !market_is_tradable($this->market)
                    || (request()->get('side') === 'buy' ? !market_is_buy_orders_allowed($this->market) : !market_is_sell_orders_allowed($this->market))) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['market'=>__('Trades are not allowed')]);
                }
                // Recheck after request validation and before reserving any balance.
                if ($reason = app(\App\Services\Market\HongKongPriceProduct::class)->tradingReason($this->market)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['market'=>__($reason)]);
                }
                $this->orderService = new OrderService();

                $this->type = request()->get('type');
                $side = request()->get('side');

                $this->trigger_price = request()->get('trigger_price', null);
                $this->trigger_condition = request()->get('trigger_condition') ?: Order::STOP_LIMIT_CONDITION_DOWN;

                if (!order_is_stop_limit($this->type)) {
                    $this->trigger_price = null;
                    $this->trigger_condition = null;
                }

                $buySide = order_is_buy($side);
                $isSellMarket = order_is_sell_market($this->type, $side);
                $isBuyMarket = order_is_buy_market($this->type, $side);

                $quantity = $isBuyMarket ? 0 : request()->get('quantity');
                $quoteQuantity = $isBuyMarket ? request()->get('quoteQuantity') : 0;
                $price = $isBuyMarket || $isSellMarket ? 0 : request()->get('price');

                $fee_rate = Setting::get('trade.taker_fee', INITIAL_TRADE_TAKER_FEE);
                $fee = 0;

                if ($isBuyMarket) {
                    $finalQuantity = $quoteQuantity;
                } else {
                    if ($buySide) {
                        $buyTotalAmount = math_multiply($quantity, $price);
                        $fee = math_percentage($buyTotalAmount, $fee_rate);
                        $finalQuantity = math_sum($buyTotalAmount, $fee);
                    } else {
                        $finalQuantity = $quantity;
                    }
                }

                $currencySide = $buySide
                    ? $this->market->quote_currency_id
                    : $this->market->base_currency_id;

                $wallet = $this->getOrCreateWalletByCurrency($this->user->id, $currencySide);

                if (!$wallet) {
                    throw new \Exception('Wallet create failed');
                }

                /*
                 * 现货下单金额兜底：
                 * 如果前端传入的买入 / 卖出金额超过当前可用交易余额，
                 * 后端自动把下单金额压到当前可用最大值，避免直接余额不足失败。
                 *
                 * 买入：
                 * - 市价买入 quoteQuantity 不能超过计价币交易账户可用余额。
                 * - 限价买入 quantity 会按可用计价币余额、价格、手续费反推最大可以买入数量。
                 *
                 * 卖出：
                 * - quantity 不能超过基础币交易账户可用余额。
                 */
                $this->normalizeSpotOrderAmountByAvailableBalance(
                    $wallet,
                    $buySide,
                    $isBuyMarket,
                    $quantity,
                    $quoteQuantity,
                    $price,
                    $fee,
                    $fee_rate,
                    $finalQuantity
                );

                /*
                 * 现货资金锁定规则：
                 * 1. 只使用交易账户。
                 * 2. 虚拟交易账户 balance_in_virtual_trade > 0 时，只扣虚拟交易账户。
                 * 3. 虚拟交易账户余额不足时，直接抛出余额不足，不混用真实账户。
                 * 4. 虚拟交易账户为 0 时，才使用真实交易账户 balance_in_trade。
                 * 5. 不再读取 / 扣除 balance_in_virtual_wallet 或 balance_in_wallet 资金账户。
                 */
                $fundingMeta = $this->lockSpotOrderFunds($wallet, $finalQuantity);

                if ($isBuyMarket) {
                    $fee = math_percentage($finalQuantity, $fee_rate);
                    $quoteQuantity = math_sub($quoteQuantity, $fee);
                }

                $insert = [
                    'id' => $this->uuid,
                    'user_id' => $this->user->id,
                    'market_id' => $this->market->id,
                    'type' => $this->type,
                    'side' => $side,
                    'initial_quantity' => $quantity,
                    'quantity' => $quantity,
                    'initial_quote_quantity' => $quoteQuantity,
                    'quote_quantity' => $quoteQuantity,
                    'price' => $price,
                    'fee' => $fee,
                    'fee_rate' => $fee_rate,
                    'settlement_domain' => $fundingMeta['account_type'],
                    'base_currency_id' => $this->market->base_currency_id,
                    'quote_currency_id' => $this->market->quote_currency_id,
                    'trigger_price' => $this->trigger_price,
                    'trigger_condition' => $this->trigger_condition,
                    'created_at' => Carbon::now()
                ];

                $insert = $this->appendSpotOrderFundingColumns($insert, $fundingMeta);

                $this->order = $this->insert($insert);
                $this->orderHistoryRepository->insert($insert);

                DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

                $orderShouldDispatched = true;

                if (
                    order_is_stop_limit($this->type) &&
                    !order_limit_should_be_processed(
                        $this->order,
                        $this->market->id,
                        $this->trigger_price,
                        $this->trigger_condition
                    )
                ) {
                    $orderShouldDispatched = false;
                }

                if ($orderShouldDispatched) {
                    if (!order_is_market($this->type)) {
                        event(new OrderBookUpdated([
                            'order' => $this->order->toArray(),
                            'name' => $this->order->market->name,
                            'decimals' => $this->order->market->quote_precision
                        ], 'store'));
                    }

                    if (!$this->orderService) {
                        $this->orderService = new OrderService();
                    }

                    $processed = $this->orderService->processOrder($this->order, true, false, $fixedRate);

                    if ($processed === false) {
                        throw new \Exception('Order matching failed');
                    }
                }

                return $this->uuid;
            }, DB_REPEAT_AFTER_DEADLOCK);
        } catch (\Throwable $e) {
            Log::error($e);

            throw $e;
        }
    }

    protected function addFuturesReferralTransactions($future, $fee, string $phase, string $eventId, ?string $domain = null)
    {
        if (!$future || !$future->user_id || math_compare($fee, 0) <= 0) return;
        $domain ??= $future->referral_balance_domain;
        if (!$domain) $domain = ($this->hasFuturesVirtualFundingMarker($future)
            || $this->hasVirtualAutoInvestMarginSource((string)$future->id)) ? 'virtual' : 'unknown';
        $sourceUser = \App\Models\User\User::find($future->user_id);
        if ($sourceUser?->is_xn || $sourceUser?->is_xm) $domain='virtual';
        app(\App\Services\Referral\ExchangeRewards::class)->record('futures_'.$phase, $eventId,
            (string)$future->id, (int)$future->user_id, (int)$future->quote_currency_id, (string)$fee, $domain);
    }

    /**
     * 合约手续费返还
     *
     * 说明：
     * 1. 原手续费 entry_fee / exit_fee 仍然完整扣除。
     * 2. 需要减免的部分通过返还方式进入用户 trade 钱包。
     * 3. 每次返还写入 futures_fee_refund_records。
     * 4. 返还记录会保存 vip_level 和 discount_rate。
     */
    protected function refundFuturesFeeToUser($future, $fee, string $feeType, $wallet = null)
    {
        if (!$future || !$future->user_id || !$future->quote_currency_id) {
            return '0';
        }

        $fee = $this->safeDecimal($fee);

        if ($this->safeCompare($fee, 0) <= 0) {
            return '0';
        }

        $user = $future->relationLoaded('user') ? $future->user : null;

        if (!$user && $this->user && (int) $this->user->id === (int) $future->user_id) {
            $user = $this->user;
        }

        if (!$user) {
            $user = User::find($future->user_id);
        }

        if (!$user) {
            return '0';
        }

        $refundRate = $this->getFuturesFeeRefundRate($user);

        if ($this->safeCompare($refundRate, 0, 8) <= 0) {
            return '0';
        }

        if ($this->safeCompare($refundRate, 100, 8) > 0) {
            $refundRate = '100';
        }

        $refundAmount = $this->safeDecimal(
            math_formatter(math_percentage($fee, $refundRate), 8)
        );

        if ($this->safeCompare($refundAmount, 0) <= 0) {
            return '0';
        }

        if (!$wallet) {
            $wallet = $this->getOrCreateWalletByCurrency(
                $future->user_id,
                $future->quote_currency_id
            );
        }

        if (!$wallet) {
            return '0';
        }

        /*
         * 虚拟合约仓位：
         * 开仓手续费返还、平仓手续费返还，全部回到虚拟交易账户 balance_in_virtual_trade。
         * 不再回到虚拟资金账户 balance_in_virtual_wallet。
         */
        if ($this->shouldCreditFuturesVirtualWallet($future, $wallet)) {
            if (!$this->walletColumnExists('balance_in_virtual_trade')) {
                throw new \Exception('Virtual trade balance field does not exist');
            }

            $this->increaseWalletField($wallet, 'balance_in_virtual_trade', $refundAmount);
        } else {
            $this->walletService->increase($wallet, $refundAmount, 'trade');
        }

        $this->createFuturesFeeRefundRecord(
            $future,
            $wallet,
            $feeType,
            $fee,
            $refundRate,
            $refundAmount,
            $user
        );

        return $refundAmount;
    }

    protected function getFuturesFeeRefundRate(User $user)
    {
        $discount = $this->getFuturesFeeDiscount($user);

        // 返还比例 = (1 - 折扣) * 100
        // VIP1: (1 - 0.9) * 100 = 10
        // VIP8: (1 - 0.20) * 100 = 80
        $refundRate = math_multiply(math_sub('1', $discount), '100');

        if ($this->safeCompare($refundRate, 0, 8) < 0) {
            return '0';
        }

        if ($this->safeCompare($refundRate, 100, 8) > 0) {
            return '100';
        }

        return $this->safeDecimal($refundRate, 8);
    }

    protected function getFuturesFeeDiscount(User $user)
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

    protected function createFuturesFeeRefundRecord(
        $future,
        $wallet,
        string $feeType,
        $originalFee,
        $refundRate,
        $refundAmount,
        ?User $user = null
    ) {
        $user = $user ?: ($future->relationLoaded('user') ? $future->user : null);

        $vipLevel = $user ? intval($user->vip ?? 0) : 0;
        $discountRate = $user ? $this->getFuturesFeeDiscount($user) : '1';

        DB::table('futures_fee_refund_records')->insert([
            'id' => generate_uuid(),
            'user_id' => $future->user_id,
            'wallet_id' => $wallet->id ?? null,
            'future_contract_id' => (string) $future->id,
            'market_id' => $future->market_id ?? null,
            'currency_id' => $future->quote_currency_id,
            'fee_type' => $feeType,
            'original_fee' => $this->safeDecimal($originalFee),
            'vip_level' => $vipLevel,
            'discount_rate' => $this->safeDecimal($discountRate, 8),
            'refund_rate' => $this->safeDecimal($refundRate, 8),
            'refund_amount' => $this->safeDecimal($refundAmount),
            'remark' => $feeType === 'entry'
                ? 'Futures entry fee refund'
                : 'Futures exit fee refund',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function cancel()
    {
        DB::beginTransaction();

        try {
            $order = Order::query()
                ->with(['market', 'user'])
                ->where('id', request()->get('uuid'))
                ->lockForUpdate()
                ->first();

            if (!$order || !$order->market || !$order->user) {
                throw new \Exception('Order not found');
            }

            $market = $order->market;
            $this->user = $order->user;

            $buySide = order_is_buy($order->side);
            $limitSide = order_is_limit($order->type);
            $stopLimitSide = order_is_stop_limit($order->type);

            $quantity = $order->quantity;
            $price = $order->price;

            $currencySide = $buySide
                ? $order->market->quote_currency_id
                : $order->market->base_currency_id;

            $wallet = $this->getOrCreateWalletByCurrency($this->user->id, $currencySide);

            if (!$wallet) {
                throw new \Exception('Wallet create failed');
            }

            if (($limitSide || $stopLimitSide) && $buySide) {
                $quantity = math_multiply($quantity, $price);
                $quantityFee = math_percentage($quantity, $order->fee_rate);
                $quantity = math_sum($quantity, $quantityFee);
            }

            /*
             * 取消订单释放资金：
             * 1. 优先按订单记录的资金来源字段释放。
             * 2. 如果 orders 表没有资金来源字段，兼容旧订单：先尝试虚拟挂单，再尝试真实挂单。
             * 3. 如果冻结余额不足，直接抛异常并回滚，避免订单取消了但金额没有返回。
             */
            $settlementDomain = \App\Services\Order\SpotFunding::domain($order);
            $this->releaseSpotOrderFunds($wallet, $quantity, $order);

            $wallet = $this->getOrCreateWalletByCurrency($this->user->id, $currencySide);

            event(new OrderBookUpdated([
                'order' => [
                    'id' => $order->id,
                    'side' => $order->side,
                    'type' => $order->type,
                    'price' => $order->price,
                    'quantity' => $order->quantity,
                    'created_at' => $order->created_at,
                    'settlement_domain' => $settlementDomain,
                ],
                'name' => $market->name,
                'decimals' => $market->quote_precision
            ], 'cancel'));

            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

            DB::afterCommit(fn () => MarketCapCalculationJob::dispatchSync($order->market));

            $order->removeFromQueue(ORDER_STATUS_CANCELLED);

            if ($order->liquidity_id && !config('app.readonly')) {
                try {
                    $api = new BinanceApi();
                    $api->cancel($order->market->name, $order->liquidity_id);
                } catch (\Throwable $e) {
                    Log::error('Error on cancelling order with Binance Liquidity Module.  Order #' . $order->id);
                    Log::error($e);
                }
            }

            DB::commit();

            (new OrderService())->dispatchOrderbookCacheUpdate($market->name);

            return true;
        } catch (\Throwable $e) {
            Log::error($e);
            DB::rollBack();

            return false;
        }
    }

    public function cancelFutures()
    {
        DB::beginTransaction();

        try {
            $order = FuturesContract::where('id', request()->get('uuid'))
                ->with(['market', 'user'])
                ->lockForUpdate()
                ->first();

            if (!$order || !$order->market || !$order->user) {
                DB::rollBack();
                return false;
            }

            // 取消 pending 限价单
            if ($order->status === 'pending' && $order->type === FuturesContract::TYPE_LIMIT) {
                $wallet = $this->getOrCreateWalletByCurrency(
                    $order->user_id,
                    $order->quote_currency_id
                );

                if (!$wallet) {
                    DB::rollBack();
                    return false;
                }

                /**
                 * pending 限价单取消：
                 * 如果使用的是模拟账户，释放 balance_in_virtual_order 到 balance_in_virtual_trade。
                 * 否则继续释放真实 balance_in_order 到 balance_in_trade，并释放理财质押。
                 */
                $tradeMarginAmount = $this->safeDecimal($order->trade_margin_amount ?? 0);

                if ($this->isFuturesUserVirtual((int) $order->user_id) || $this->isFuturesVirtualPosition($order, $wallet)) {
                    $this->releaseFuturesVirtualMargin($wallet, $tradeMarginAmount);
                } elseif ($this->safeCompare($tradeMarginAmount, 0) > 0) {
                    $this->walletService->decrease($wallet, $tradeMarginAmount, 'order');
                    $this->walletService->increase($wallet, $tradeMarginAmount, 'trade');
                }

                $this->releaseAutoInvestMarginLocks((string) $order->id);

                $order->delete();

                DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

                DB::commit();

                $this->dispatchCopyTradingClose((string) $order->id);

                return true;
            }

            // 持仓时间限制
            $timeframeEnabled = Setting::get('futures.timeframe_enabled', false);
            $timeframeSeconds = (int) ($order->timeframe_seconds ?? 0);

            if (
                $timeframeEnabled &&
                $order->status === 'active' &&
                $timeframeSeconds > 0 &&
                !is_null($order->activated_at)
            ) {
                $elapsed = Carbon::now()->diffInSeconds($order->activated_at);

                if ($elapsed < $timeframeSeconds) {
                    DB::rollBack();
                    return false;
                }
            }

            if ($order->status !== 'active') {
                DB::rollBack();
                return false;
            }

            $this->user = $order->user;

            $wallet = $this->getOrCreateWalletByCurrency(
                $this->user->id,
                $order->market->quote_currency_id
            );

            if (!$wallet) {
                DB::rollBack();
                return false;
            }

            $priceQuote = app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($order);
            $marketPriceRaw = $priceQuote['price'];
            $order->closing_price_source = $priceQuote['source'];

            if (!is_numeric($marketPriceRaw)) {
                DB::rollBack();
                return false;
            }

            $marketPrice = $this->safeDecimal($marketPriceRaw, $order->market->quote_precision);

            /**
             * 关键：同步有效爆仓价。
             * 有效爆仓价会把 trade_margin_amount + auto_invest_margin_amount - entry_fee
             * 作为保证金一起计算，避免理财质押资金没有参与爆仓价导致提前爆仓。
             */
            $effectiveLiquidationPrice = $this->getFuturesEffectiveLiquidationPrice($order);

            if ($this->safeCompare($effectiveLiquidationPrice, 0, $order->market->quote_precision) > 0) {
                $order->liquidation_price = $effectiveLiquidationPrice;
            }

            $quantity = $this->safeDecimal($order->quantity ?? 0);
            $entryPrice = $this->safeDecimal($order->price ?? 0);
            $balance = $this->safeDecimal($order->balance ?? 0);
            $leverage = $this->safeDecimal($order->leverage ?? 1);

            if (
                $this->safeCompare($quantity, 0) <= 0 ||
                $this->safeCompare($entryPrice, 0) <= 0 ||
                $this->safeCompare($balance, 0) < 0 ||
                $this->safeCompare($leverage, 0) <= 0
            ) {
                DB::rollBack();
                return false;
            }

            // 计算 pnl
            $pnl = $this->safeDecimal(
                futures_pnl_calculate($quantity, $entryPrice, $marketPrice, $leverage, $order->is_long)
            );

            // 计算 pnlAmount 和释放金额
            if ($this->safeCompare($pnl, 0) == 0) {
                $pnlAmount = '0';
                $releasedAmount = $balance;
            } else {
                $pnlAmount = $this->safeDecimal(abs(math_percentage($balance, $pnl)));

                if ($this->safeCompare($pnl, 0) > 0) {
                    $releasedAmount = math_sum($balance, $pnlAmount);
                } else {
                    $releasedAmount = math_sub($balance, $pnlAmount);
                }

                $releasedAmount = $this->safeDecimal($releasedAmount);
            }

            /**
             * 爆仓或超额亏损时，释放金额不能为负数。
             */
            if ($this->safeCompare($releasedAmount, 0) < 0) {
                $releasedAmount = '0';
            }

            // 平仓手续费：原始手续费照常扣除，不在这里做比例减免
            $exitFeeRate = $this->safeDecimal(Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE));
            $exitFee = $this->safeDecimal(math_percentage($releasedAmount, $exitFeeRate));

            $releasedAmountAfterFee = $this->safeDecimal(math_sub($releasedAmount, $exitFee));

            if ($this->safeCompare($releasedAmountAfterFee, 0) < 0) {
                $releasedAmountAfterFee = '0';
                $exitFee = $releasedAmount;
            }

            // 平仓手续费返佣
            if ($this->safeCompare($exitFee, 0) > 0) {
                $this->addFuturesReferralTransactions($order, $exitFee, 'exit', (string)$order->id);
            }

            /**
             * 判断是否爆仓。
             * 这里使用 getFuturesEffectiveLiquidationPrice() 重新计算后的有效爆仓价。
             *
             * 注意：不能只看 releasedAmountAfterFee。
             * 有些情况下 releasedAmount 被修正为 0 以后，手续费也可能是 0，
             * 这时必须仍然识别为爆仓，否则理财订单可能继续保持 active。
             */
            $isLiquidatedByPrice = $this->isFuturesLiquidatedByPrice($order, $marketPrice);

            $isLiquidatedByLoss = $this->safeCompare($releasedAmount, 0) <= 0
                || $this->safeCompare($releasedAmountAfterFee, 0) <= 0;

            $isLiquidated = $isLiquidatedByPrice || $isLiquidatedByLoss;

            /**
             * 平仓 / 爆仓资金结算。
             * 如果该仓位使用模拟账户保证金，释放结果进入 balance_in_virtual_trade。
             * 否则保留原来的真实账户 / 理财质押结算规则。
             */
            if ($this->isFuturesVirtualPosition($order, $wallet)) {
                $settlementResult = $this->settleFuturesVirtualMargin(
                    $order,
                    $wallet,
                    $releasedAmountAfterFee,
                    $isLiquidated
                );
            } else {
                $settlementResult = $this->settleFuturesMarginByAutoInvestRule(
                    $order,
                    $wallet,
                    $releasedAmountAfterFee,
                    $isLiquidated
                );
            }

            /**
             * 兜底同步理财订单状态：
             * 1. 重新计算该理财订单仍被其他仓位占用的 used_margin。
             * 2. amount <= 0 的订单必须关闭，不能继续 active。
             * 3. 爆仓但还有剩余 amount 的订单继续 active，按剩余金额计息。
             */
            $this->syncAutoInvestOrdersAfterFuturesSettlement((string) $order->id, $isLiquidated);

            // 手续费减免部分通过返还入账，并写返还记录
            if ($this->safeCompare($exitFee, 0) > 0) {
                $this->refundFuturesFeeToUser($order, $exitFee, 'exit', $wallet);
            }

            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

            // 更新订单
            $order->released_amount = $releasedAmountAfterFee;
            $order->exit_fee = $exitFee;
            $order->close_price = $marketPrice;
            $order->status = 'closed';
            $order->pnl = $pnl;
            $order->liquidation_price = $effectiveLiquidationPrice;

            if ($this->schemaHasColumnCached($order->getTable(), 'trade_margin_released_amount')) {
                $order->trade_margin_released_amount = $settlementResult['trade_released_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'auto_invest_released_amount')) {
                $order->auto_invest_released_amount = $settlementResult['auto_invest_released_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'auto_invest_consumed_amount')) {
                $order->auto_invest_consumed_amount = $settlementResult['auto_invest_consumed_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'liquidated')) {
                $order->liquidated = $isLiquidated ? 1 : 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'liquidated_at') && $isLiquidated) {
                $order->liquidated_at = now();
            }

            $order->save();

            DB::commit();

            $this->dispatchCopyTradingClose((string) $order->id);

            return true;
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            Log::error($e);
            DB::rollBack();

            return false;
        }
    }

    public function forceLiquidateFutures($uuid): array
    {
        DB::beginTransaction();

        try {
            $order = FuturesContract::where('id', $uuid)
                ->with(['market', 'user'])
                ->lockForUpdate()
                ->first();

            if (!$order || !$order->market || !$order->user) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Order not found',
                    'status' => 404,
                ];
            }

            if (in_array($order->status, ['closed', 'liquidated'], true)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Order already closed or liquidated',
                    'status' => 422,
                ];
            }

            $wallet = $this->getOrCreateWalletByCurrency(
                $order->user_id,
                $order->quote_currency_id ?: $order->market->quote_currency_id
            );

            if (!$wallet) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Wallet not found',
                    'status' => 422,
                ];
            }

            if ($order->status === 'pending' && $order->type === FuturesContract::TYPE_LIMIT) {
                $tradeMarginAmount = $this->safeDecimal($order->trade_margin_amount ?? 0);

                if ($this->isFuturesUserVirtual((int) $order->user_id) || $this->isFuturesVirtualPosition($order, $wallet)) {
                    $this->releaseFuturesVirtualMargin($wallet, $tradeMarginAmount);
                } elseif ($this->safeCompare($tradeMarginAmount, 0) > 0) {
                    $this->walletService->decrease($wallet, $tradeMarginAmount, 'order');
                    $this->walletService->increase($wallet, $tradeMarginAmount, 'trade');
                }

                $this->releaseAutoInvestMarginLocks((string) $order->id, true);
                $this->syncAutoInvestOrdersAfterFuturesSettlement((string) $order->id, true);

                $releasedAmount = $this->safeDecimal(math_sum($order->balance ?? 0, $order->entry_fee ?? 0));

                $order->released_amount = $releasedAmount;
                $order->exit_fee = 0;
                $order->pnl = 0;
                $order->status = 'liquidated';

                if ($this->schemaHasColumnCached($order->getTable(), 'liquidated')) {
                    $order->liquidated = 1;
                }

                if ($this->schemaHasColumnCached($order->getTable(), 'liquidated_at')) {
                    $order->liquidated_at = now();
                }

                $order->save();

                DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Force liquidated successfully',
                    'status' => 200,
                ];
            }

            if ($order->status !== 'active') {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Only active orders can be liquidated',
                    'status' => 422,
                ];
            }

            $priceQuote = app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($order);
            $marketPriceRaw = $priceQuote['price'];
            $order->closing_price_source = $priceQuote['source'];

            $precision = (int) ($order->market->quote_precision ?? 8);

            if (
                !is_numeric($marketPriceRaw) ||
                $this->safeCompare($marketPriceRaw, 0, $precision) <= 0
            ) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Market price not found',
                    'status' => 422,
                ];
            }

            $marketPrice = $this->safeDecimal($marketPriceRaw, $precision);
            $effectiveLiquidationPrice = $this->getFuturesEffectiveLiquidationPrice($order);

            if ($this->safeCompare($effectiveLiquidationPrice, 0, $order->market->quote_precision) > 0) {
                $order->liquidation_price = $effectiveLiquidationPrice;
            }

            $quantity = $this->safeDecimal($order->quantity ?? 0);
            $entryPrice = $this->safeDecimal($order->price ?? 0);
            $balance = $this->safeDecimal($order->balance ?? 0);
            $leverage = $this->safeDecimal($order->leverage ?? 1);

            if (
                $this->safeCompare($quantity, 0) <= 0 ||
                $this->safeCompare($entryPrice, 0) <= 0 ||
                $this->safeCompare($balance, 0) < 0 ||
                $this->safeCompare($leverage, 0) <= 0
            ) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Invalid futures order amount',
                    'status' => 422,
                ];
            }

            $pnl = $this->safeDecimal(
                futures_pnl_calculate($quantity, $entryPrice, $marketPrice, $leverage, $order->is_long)
            );

            if ($this->safeCompare($pnl, 0) === 0) {
                $pnlAmount = '0';
                $releasedAmount = $balance;
            } else {
                $pnlAmount = $this->safeDecimal(abs(math_percentage($balance, $pnl)));
                $releasedAmount = $this->safeCompare($pnl, 0) > 0
                    ? math_sum($balance, $pnlAmount)
                    : math_sub($balance, $pnlAmount);
                $releasedAmount = $this->safeDecimal($releasedAmount);
            }

            if ($this->safeCompare($releasedAmount, 0) < 0) {
                $releasedAmount = '0';
            }

            $exitFeeRate = $this->safeDecimal(Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE));
            $exitFee = $this->safeDecimal(math_percentage($releasedAmount, $exitFeeRate));
            $releasedAmountAfterFee = $this->safeDecimal(math_sub($releasedAmount, $exitFee));

            if ($this->safeCompare($releasedAmountAfterFee, 0) < 0) {
                $releasedAmountAfterFee = '0';
                $exitFee = $releasedAmount;
            }

            if ($this->safeCompare($exitFee, 0) > 0) {
                $this->addFuturesReferralTransactions($order, $exitFee, 'exit', (string)$order->id);
            }

            if ($this->isFuturesVirtualPosition($order, $wallet)) {
                $settlementResult = $this->settleFuturesVirtualMargin(
                    $order,
                    $wallet,
                    $releasedAmountAfterFee,
                    true
                );
            } else {
                $settlementResult = $this->settleFuturesMarginByAutoInvestRule(
                    $order,
                    $wallet,
                    $releasedAmountAfterFee,
                    true,
                    'wallet'
                );
            }

            $this->syncAutoInvestOrdersAfterFuturesSettlement((string) $order->id, true);

            if ($this->safeCompare($exitFee, 0) > 0) {
                $this->refundFuturesFeeToUser($order, $exitFee, 'exit', $wallet);
            }

            if ($this->safeCompare($pnl, 0) > 0 && $this->safeCompare($pnlAmount, 0) > 0) {
                $currencyRepo = new CurrencyRepository();
                $quoteCurrency = $currencyRepo->get($order->market->quote_currency_id);

                if ($quoteCurrency) {
                    $usdPrice = $currencyRepo->currencyPriceInUsd($quoteCurrency);
                    $pnlUsd = math_multiply($pnlAmount, $usdPrice);

                    $lockedUser = $order->user()->lockForUpdate()->first();
                    $current = $lockedUser->cumulative_earnings_usd ?? '0';
                    $lockedUser->cumulative_earnings_usd = math_sum($current, $pnlUsd);
                    $lockedUser->save();
                }
            }

            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

            $order->released_amount = $releasedAmountAfterFee;
            $order->exit_fee = $exitFee;
            $order->close_price = $marketPrice;
            $order->status = 'liquidated';
            $order->pnl = $pnl;
            $order->liquidation_price = $effectiveLiquidationPrice;

            if ($this->schemaHasColumnCached($order->getTable(), 'trade_margin_released_amount')) {
                $order->trade_margin_released_amount = $settlementResult['trade_released_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'auto_invest_released_amount')) {
                $order->auto_invest_released_amount = $settlementResult['auto_invest_released_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'auto_invest_consumed_amount')) {
                $order->auto_invest_consumed_amount = $settlementResult['auto_invest_consumed_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'liquidated')) {
                $order->liquidated = 1;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'liquidated_at')) {
                $order->liquidated_at = now();
            }

            $order->save();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Force liquidated successfully',
                'status' => 200,
            ];
        } catch (\Throwable $e) {
            Log::error($e);
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Force liquidation failed',
                'status' => 500,
            ];
        }
    }

    public function closeFuturesPositionAtPrice($uuid, $marketPrice, string $closeReason = 'system'): array
    {
        DB::beginTransaction();

        try {
            $order = FuturesContract::where('id', $uuid)
                ->with(['market', 'user'])
                ->lockForUpdate()
                ->first();

            if (!$order || !$order->market || !$order->user) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Order not found',
                    'status' => 404,
                ];
            }

            if ($order->status !== 'active') {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Only active orders can be closed',
                    'status' => 422,
                ];
            }

            $priceQuote = app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($order);
            $marketPrice = $priceQuote['price'];
            $order->closing_price_source = $priceQuote['source'];
            $precision = (int) ($order->market->quote_precision ?? 8);

            if (
                !is_numeric($marketPrice) ||
                $this->safeCompare($marketPrice, 0, $precision) <= 0
            ) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Market price not found',
                    'status' => 422,
                ];
            }

            $wallet = $this->getOrCreateWalletByCurrency(
                $order->user_id,
                $order->quote_currency_id ?: $order->market->quote_currency_id
            );

            if (!$wallet) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Wallet not found',
                    'status' => 422,
                ];
            }

            $marketPrice = $this->safeDecimal($marketPrice, $precision);
            $effectiveLiquidationPrice = $this->getFuturesEffectiveLiquidationPrice($order);

            if ($this->safeCompare($effectiveLiquidationPrice, 0, $order->market->quote_precision) > 0) {
                $order->liquidation_price = $effectiveLiquidationPrice;
            }

            $quantity = $this->safeDecimal($order->quantity ?? 0);
            $entryPrice = $this->safeDecimal($order->price ?? 0);
            $balance = $this->safeDecimal($order->balance ?? 0);
            $leverage = $this->safeDecimal($order->leverage ?? 1);

            if (
                $this->safeCompare($quantity, 0) <= 0 ||
                $this->safeCompare($entryPrice, 0) <= 0 ||
                $this->safeCompare($balance, 0) < 0 ||
                $this->safeCompare($leverage, 0) <= 0
            ) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Invalid futures order amount',
                    'status' => 422,
                ];
            }

            $pnl = $this->safeDecimal(
                futures_pnl_calculate($quantity, $entryPrice, $marketPrice, $leverage, $order->is_long)
            );

            if ($this->safeCompare($pnl, 0) === 0) {
                $pnlAmount = '0';
                $releasedAmount = $balance;
            } else {
                $pnlAmount = $this->safeDecimal(abs(math_percentage($balance, $pnl)));
                $releasedAmount = $this->safeCompare($pnl, 0) > 0
                    ? math_sum($balance, $pnlAmount)
                    : math_sub($balance, $pnlAmount);
                $releasedAmount = $this->safeDecimal($releasedAmount);
            }

            if ($this->safeCompare($releasedAmount, 0) < 0) {
                $releasedAmount = '0';
            }

            $exitFeeRate = $this->safeDecimal(Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE));
            $exitFee = $this->safeDecimal(math_percentage($releasedAmount, $exitFeeRate));
            $releasedAmountAfterFee = $this->safeDecimal(math_sub($releasedAmount, $exitFee));

            if ($this->safeCompare($releasedAmountAfterFee, 0) < 0) {
                $releasedAmountAfterFee = '0';
                $exitFee = $releasedAmount;
            }

            if ($this->safeCompare($exitFee, 0) > 0) {
                $this->addFuturesReferralTransactions($order, $exitFee, 'exit', (string)$order->id);
            }

            $isLiquidatedByPrice = $this->isFuturesLiquidatedByPrice($order, $marketPrice);
            $isLiquidatedByLoss = $this->safeCompare($releasedAmount, 0) <= 0
                || $this->safeCompare($releasedAmountAfterFee, 0) <= 0;
            $isLiquidated = $isLiquidatedByPrice || $isLiquidatedByLoss;

            if ($this->isFuturesVirtualPosition($order, $wallet)) {
                $settlementResult = $this->settleFuturesVirtualMargin(
                    $order,
                    $wallet,
                    $releasedAmountAfterFee,
                    $isLiquidated
                );
            } else {
                $settlementResult = $this->settleFuturesMarginByAutoInvestRule(
                    $order,
                    $wallet,
                    $releasedAmountAfterFee,
                    $isLiquidated
                );
            }

            $this->syncAutoInvestOrdersAfterFuturesSettlement((string) $order->id, $isLiquidated);

            if ($this->safeCompare($exitFee, 0) > 0) {
                $this->refundFuturesFeeToUser($order, $exitFee, 'exit', $wallet);
            }

            if ($this->safeCompare($pnl, 0) > 0 && $this->safeCompare($pnlAmount, 0) > 0) {
                $currencyRepo = new CurrencyRepository();
                $quoteCurrency = $currencyRepo->get($order->market->quote_currency_id);

                if ($quoteCurrency) {
                    $usdPrice = $currencyRepo->currencyPriceInUsd($quoteCurrency);
                    $pnlUsd = math_multiply($pnlAmount, $usdPrice);

                    $lockedUser = $order->user()->lockForUpdate()->first();

                    if ($lockedUser) {
                        $current = $lockedUser->cumulative_earnings_usd ?? '0';
                        $lockedUser->cumulative_earnings_usd = math_sum($current, $pnlUsd);
                        $lockedUser->save();
                    }
                }
            }

            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

            $order->released_amount = $releasedAmountAfterFee;
            $order->exit_fee = $exitFee;
            $order->close_price = $marketPrice;
            $order->status = 'closed';
            $order->pnl = $pnl;
            $order->liquidation_price = $effectiveLiquidationPrice;

            if ($this->schemaHasColumnCached($order->getTable(), 'trade_margin_released_amount')) {
                $order->trade_margin_released_amount = $settlementResult['trade_released_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'auto_invest_released_amount')) {
                $order->auto_invest_released_amount = $settlementResult['auto_invest_released_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'auto_invest_consumed_amount')) {
                $order->auto_invest_consumed_amount = $settlementResult['auto_invest_consumed_amount'] ?? 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'liquidated')) {
                $order->liquidated = $isLiquidated ? 1 : 0;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'liquidated_at') && $isLiquidated) {
                $order->liquidated_at = now();
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'close_reason')) {
                $order->close_reason = $closeReason;
            }

            if ($this->schemaHasColumnCached($order->getTable(), 'closed_at')) {
                $order->closed_at = now();
            }

            $order->save();

            DB::commit();

            $this->dispatchCopyTradingClose((string) $order->id);

            return [
                'success' => true,
                'message' => 'Futures position closed successfully',
                'status' => 200,
                'liquidated' => $isLiquidated,
            ];
        } catch (\Throwable $e) {
            Log::error($e);
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Futures position close failed',
                'status' => 500,
            ];
        }
    }


    /**
     * 合约保证金锁定规则：
     * 1. 如果 balance_in_virtual_wallet > 0，优先并且只使用模拟资金账户。
     * 2. 如果 balance_in_virtual_wallet 为 0，但 balance_in_virtual_trade > 0，兼容使用模拟交易账户。
     * 3. 模拟账户余额不足时直接抛错，不混用真实 balance_in_trade。
     * 4. 模拟合约保证金统一锁到 balance_in_virtual_order，用于平仓/撤单时识别资金来源。
     * 5. 没有模拟余额时，保留原来的真实交易账户 + 理财质押逻辑。
     */
private function allocateFuturesMarginWithVirtualWallet($wallet, int $userId, int $currencyId, $requiredAmount, string $sourceId, string $sourceStatus = 'active'): array
{
    $requiredAmount = $this->safeDecimal($requiredAmount);

    if ($this->safeCompare($requiredAmount, 0) <= 0) {
        throw new \Exception('Invalid margin amount');
    }

    /*
     * 合约开仓资金规则：
     * 1. 永远不能扣资金账户 balance_in_wallet / balance_in_virtual_wallet。
     * 2. 普通账户：先扣真实交易账户 balance_in_trade，不够再用理财订单。
     * 3. 虚拟账户 users.is_xn = true：先扣虚拟交易账户 balance_in_virtual_trade，不够再用理财订单。
     * 4. 理财订单不限制币种，BTC/ETH/OKR 等都会折算成当前合约计价币种后参与保证金。
     */
    /*
     * store() 通过 getOrCreateWalletByCurrency() 已经对该钱包执行了 FOR UPDATE。
     * 直接复用已锁定的模型，避免每次开仓再发送一次相同的锁查询。
     */
    $freshWallet = $wallet;

    if (!$freshWallet || !$freshWallet->exists) {
        throw new \Exception('Wallet not found');
    }

    $isVirtualUser = $this->isFuturesUserVirtual($userId);

    /*
     * 如果用户是虚拟账户，或者当前币种存在虚拟交易余额，则本次仓位按虚拟仓位处理。
     * 注意：这里只看 balance_in_virtual_trade，不看 balance_in_virtual_wallet，避免动用资金账户。
     */
    $virtualTradeBalance = $this->walletColumnExists('balance_in_virtual_trade')
        ? $this->safeDecimal($freshWallet->balance_in_virtual_trade ?? 0)
        : '0';

    $shouldUseVirtualTrade = $isVirtualUser || $this->safeCompare($virtualTradeBalance, 0) > 0;

    if ($shouldUseVirtualTrade) {
        if (!$this->walletColumnExists('balance_in_virtual_trade')) {
            throw new \Exception('Virtual trade balance field does not exist');
        }

        $tradeUseAmount = $this->safeCompare($virtualTradeBalance, $requiredAmount) >= 0
            ? $requiredAmount
            : $virtualTradeBalance;

        $remainingAmount = $this->safeDecimal(math_sub($requiredAmount, $tradeUseAmount));

        if ($this->safeCompare($tradeUseAmount, 0) > 0) {
            $this->decreaseWalletField(
                $freshWallet,
                'balance_in_virtual_trade',
                $tradeUseAmount,
                true
            );
        }

        $autoInvestUseAmount = '0';

        if ($this->safeCompare($remainingAmount, 0) > 0) {
            $autoInvestUseAmount = $this->lockAutoInvestMargin(
                $userId,
                $currencyId,
                $remainingAmount,
                $sourceId
            );
        }

        $allocatedTotal = $this->safeDecimal(math_sum($tradeUseAmount, $autoInvestUseAmount));

        if ($this->safeCompare($allocatedTotal, $requiredAmount) < 0) {
            if ($this->safeCompare($tradeUseAmount, 0) > 0) {
                $this->increaseWalletField(
                    $freshWallet,
                    'balance_in_virtual_trade',
                    $tradeUseAmount
                );
            }

            if ($this->safeCompare($autoInvestUseAmount, 0) > 0) {
                $this->releaseAutoInvestMarginLocks($sourceId);
            }

            throw new \Exception(
                'Insufficient virtual futures balance. Available trade: ' . $virtualTradeBalance .
                ', Auto Invest locked: ' . $autoInvestUseAmount .
                ', Required: ' . $requiredAmount
            );
        }

        return [
            'account_type' => 'virtual',
            'source_field' => 'balance_in_virtual_trade',
            'source_fields' => [
                'balance_in_virtual_trade' => $this->safeDecimal($tradeUseAmount),
                'auto_invest_orders' => $this->safeDecimal($autoInvestUseAmount),
            ],
            'order_field' => 'balance_in_virtual_order',
            'trade_margin_amount' => $this->safeDecimal($tradeUseAmount),
            'auto_invest_margin_amount' => $this->safeDecimal($autoInvestUseAmount),
            'total_margin_amount' => $this->safeDecimal($allocatedTotal),
            'source_status' => $sourceStatus,
        ];
    }

    /*
     * 非虚拟账户：只允许真实交易账户 + 理财订单。
     * 永远不扣真实资金账户 balance_in_wallet。
     */
    $allocation = $this->allocateFuturesMargin(
        $freshWallet,
        $userId,
        $currencyId,
        $requiredAmount,
        $sourceId,
        $sourceStatus
    );

    $allocation['account_type'] = 'real';
    $allocation['source_field'] = 'balance_in_trade';
    $allocation['order_field'] = 'balance_in_order';

    return $allocation;
}

private function lockFuturesVirtualMarginFromTwoFields(Wallet $wallet, $requiredAmount): array
{
    $requiredAmount = $this->safeDecimal($requiredAmount);
    $remaining = $requiredAmount;

    $usedFields = [
        'balance_in_virtual_wallet' => '0',
        'balance_in_virtual_trade' => '0',
    ];

    /*
     * 扣款顺序：
     * 1. 先扣虚拟资金账户
     * 2. 不够再扣虚拟交易账户
     *
     * 如果你想优先扣虚拟交易账户，把下面数组顺序调换即可。
     */
    $sourceFields = [
        'balance_in_virtual_wallet',
        'balance_in_virtual_trade',
    ];

    foreach ($sourceFields as $sourceField) {
        if ($this->safeCompare($remaining, 0) <= 0) {
            break;
        }

        if (!$this->walletColumnExists($sourceField)) {
            continue;
        }

        $wallet->refresh();

        $available = $this->safeDecimal($wallet->{$sourceField} ?? 0);

        if ($this->safeCompare($available, 0) <= 0) {
            continue;
        }

        $useAmount = $this->safeCompare($available, $remaining) >= 0
            ? $remaining
            : $available;

        if ($this->safeCompare($useAmount, 0) <= 0) {
            continue;
        }

        $this->moveWalletAmount(
            $wallet,
            $sourceField,
            'balance_in_virtual_order',
            $useAmount
        );

        $usedFields[$sourceField] = $this->safeDecimal(
            math_sum($usedFields[$sourceField], $useAmount)
        );

        $remaining = $this->safeDecimal(
            math_sub($remaining, $useAmount)
        );
    }

    if ($this->safeCompare($remaining, 0) > 0) {
        throw new \Exception('Insufficient virtual futures balance. Required remaining: ' . $remaining);
    }

    return $usedFields;
}
private function getFuturesVirtualTotalBalance(Wallet $wallet): string
{
    $wallet->refresh();

    $total = '0';

    if ($this->walletColumnExists('balance_in_virtual_wallet')) {
        $total = $this->safeDecimal(math_sum(
            $total,
            $wallet->balance_in_virtual_wallet ?? 0
        ));
    }

    if ($this->walletColumnExists('balance_in_virtual_trade')) {
        $total = $this->safeDecimal(math_sum(
            $total,
            $wallet->balance_in_virtual_trade ?? 0
        ));
    }

    return $this->safeDecimal($total);
}

private function lockFuturesVirtualMargin(Wallet $wallet, $requiredAmount): array
{
    $requiredAmount = $this->safeDecimal($requiredAmount);
    $remaining = $requiredAmount;

    $usedFields = [
        'balance_in_virtual_wallet' => '0',
        'balance_in_virtual_trade' => '0',
    ];

    $sourceFields = [
        'balance_in_virtual_wallet',
        'balance_in_virtual_trade',
    ];

    foreach ($sourceFields as $sourceField) {
        if ($this->safeCompare($remaining, 0) <= 0) {
            break;
        }

        if (!$this->walletColumnExists($sourceField)) {
            continue;
        }

        $wallet->refresh();

        $available = $this->safeDecimal($wallet->{$sourceField} ?? 0);

        if ($this->safeCompare($available, 0) <= 0) {
            continue;
        }

        $useAmount = $this->safeCompare($available, $remaining) >= 0
            ? $remaining
            : $available;

        if ($this->safeCompare($useAmount, 0) <= 0) {
            continue;
        }

        $this->moveWalletAmount(
            $wallet,
            $sourceField,
            'balance_in_virtual_order',
            $useAmount
        );

        $usedFields[$sourceField] = $this->safeDecimal(
            math_sum($usedFields[$sourceField], $useAmount)
        );

        $remaining = $this->safeDecimal(
            math_sub($remaining, $useAmount)
        );
    }

    if ($this->safeCompare($remaining, 0) > 0) {
        throw new \Exception('Insufficient virtual futures balance. Required remaining: ' . $remaining);
    }

    return $usedFields;
}

private function getFuturesVirtualSourceField(Wallet $wallet): ?string
{
    $wallet->refresh();

    $virtualWallet = $this->walletColumnExists('balance_in_virtual_wallet')
        ? $this->safeDecimal($wallet->balance_in_virtual_wallet ?? 0)
        : '0';

    $virtualTrade = $this->walletColumnExists('balance_in_virtual_trade')
        ? $this->safeDecimal($wallet->balance_in_virtual_trade ?? 0)
        : '0';

    if ($this->safeCompare($virtualWallet, 0) > 0 && $this->safeCompare($virtualTrade, 0) > 0) {
        return 'balance_in_virtual_wallet,balance_in_virtual_trade';
    }

    if ($this->safeCompare($virtualWallet, 0) > 0) {
        return 'balance_in_virtual_wallet';
    }

    if ($this->safeCompare($virtualTrade, 0) > 0) {
        return 'balance_in_virtual_trade';
    }

    return null;
}
private function isFuturesVirtualPosition($future, Wallet $wallet): bool
{
    if (!$future || !$wallet) {
        return false;
    }

    $autoInvestMargin = $this->safeDecimal($future->auto_invest_margin_amount ?? 0);
    $tradeMargin = $this->safeDecimal($future->trade_margin_amount ?? 0);
    $totalMargin = $this->safeDecimal($future->total_margin_amount ?? 0);

    /*
     * 使用了理财质押的仓位，需要走理财结算逻辑。
     * 但手续费返还是否回虚拟账户，由 shouldCreditFuturesVirtualWallet() 单独判断。
     */
    if (
        $this->safeCompare($autoInvestMargin, 0) > 0 ||
        $this->hasActiveAutoInvestMarginLocks((string) ($future->id ?? ''))
    ) {
        return false;
    }

    if ($this->isFuturesUserVirtual((int) ($future->user_id ?? 0))) {
        return true;
    }

    $wallet->refresh();

    if ($this->walletColumnExists('balance_in_virtual_order')) {
        $virtualOrder = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0);

        if ($this->safeCompare($virtualOrder, 0) > 0) {
            return true;
        }
    }

    if (
        $this->safeCompare($tradeMargin, 0) > 0 ||
        $this->safeCompare($totalMargin, 0) > 0
    ) {
        $virtualTotal = '0';

        $virtualFields = [
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
            'balance_in_virtual_withdraw',
        ];

        foreach ($virtualFields as $field) {
            if (!$this->walletColumnExists($field)) {
                continue;
            }

            $virtualTotal = $this->safeDecimal(
                math_sum($virtualTotal, $wallet->{$field} ?? 0)
            );
        }

        if ($this->safeCompare($virtualTotal, 0) > 0) {
            return true;
        }
    }

    return false;
}
private function shouldCreditFuturesVirtualWallet($future, ?Wallet $wallet): bool
{
    if (!$future || !$wallet) {
        return false;
    }

    if ($this->isFuturesUserVirtual((int) ($future->user_id ?? 0))) {
        return true;
    }

    if ($this->hasFuturesVirtualFundingMarker($future)) {
        return true;
    }

    if ($this->hasVirtualAutoInvestMarginSource((string) ($future->id ?? ''))) {
        return true;
    }

    return $this->isFuturesVirtualPosition($future, $wallet);
}

private function hasFuturesVirtualFundingMarker($future): bool
{
    if (!$future) {
        return false;
    }

    $table = method_exists($future, 'getTable')
        ? $future->getTable()
        : (new FuturesContract())->getTable();

    foreach (['account_type', 'funding_account_type', 'wallet_account_type'] as $column) {
        if ($this->getOptionalModelAttribute($future, $table, $column) === 'virtual') {
            return true;
        }
    }

    foreach (['balance_source_field', 'source_balance_field', 'source_field', 'virtual_balance_source'] as $column) {
        if ($this->isVirtualBalanceField($this->getOptionalModelAttribute($future, $table, $column))) {
            return true;
        }
    }

    $isVirtualPosition = $this->getOptionalModelAttribute($future, $table, 'is_virtual_position');

    if ((string) $isVirtualPosition === '1' || $isVirtualPosition === true) {
        return true;
    }

    $virtualMarginAmount = $this->getOptionalModelAttribute($future, $table, 'virtual_margin_amount');

    return $this->safeCompare($virtualMarginAmount ?? 0, 0) > 0;
}

private function getOptionalModelAttribute($model, string $table, string $column)
{
    try {
        if (method_exists($model, 'getAttribute')) {
            if (!$this->schemaHasColumnCached($table, $column)) {
                return null;
            }

            return $model->getAttribute($column);
        }
    } catch (\Throwable $e) {
        return null;
    }

    return is_object($model) && isset($model->{$column}) ? $model->{$column} : null;
}

private function isVirtualBalanceField($field): bool
{
    if ($field === null || $field === '') {
        return false;
    }

    foreach (explode(',', (string) $field) as $part) {
        if (in_array(trim($part), ['balance_in_virtual_wallet', 'balance_in_virtual_trade'], true)) {
            return true;
        }
    }

    return false;
}

private function hasVirtualAutoInvestMarginSource(string $sourceId): bool
{
    if (
        $sourceId === '' ||
        !$this->schemaHasTableCached('auto_invest_margin_locks') ||
        !$this->schemaHasTableCached('auto_invest_orders')
    ) {
        return false;
    }

    $selectColumns = ['aio.id'];

    foreach (['meta', 'source_account_type', 'account_type', 'source_balance_field', 'source_field'] as $column) {
        if ($this->schemaHasColumnCached('auto_invest_orders', $column)) {
            $selectColumns[] = 'aio.' . $column;
        }
    }

    $orders = DB::table('auto_invest_margin_locks as locks')
        ->join('auto_invest_orders as aio', 'aio.id', '=', 'locks.auto_invest_order_id')
        ->where('locks.source_type', 'futures')
        ->where('locks.source_id', $sourceId)
        ->select($selectColumns)
        ->get();

    foreach ($orders as $order) {
        if (
            ($order->source_account_type ?? null) === 'virtual' ||
            ($order->account_type ?? null) === 'virtual' ||
            $this->isVirtualBalanceField($order->source_balance_field ?? null) ||
            $this->isVirtualBalanceField($order->source_field ?? null)
        ) {
            return true;
        }

        $meta = [];

        if (!empty($order->meta)) {
            $decoded = json_decode((string) $order->meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if (
            ($meta['source_account_type'] ?? null) === 'virtual' ||
            ($meta['account_type'] ?? null) === 'virtual' ||
            $this->isVirtualBalanceField($meta['source_balance_field'] ?? null) ||
            $this->isVirtualBalanceField($meta['source_field'] ?? null)
        ) {
            return true;
        }
    }

    return false;
}


    private function releaseFuturesVirtualMargin(Wallet $wallet, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        if (!$this->walletColumnExists('balance_in_virtual_order') || !$this->walletColumnExists('balance_in_virtual_trade')) {
            return;
        }

        $wallet->refresh();
        $virtualOrderBalance = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0);

        if ($this->safeCompare($virtualOrderBalance, 0) <= 0) {
            return;
        }

        $releaseAmount = $this->safeCompare($virtualOrderBalance, $amount) >= 0 ? $amount : $virtualOrderBalance;

        if ($this->safeCompare($releaseAmount, 0) > 0) {
            /*
             * 虚拟合约限价单取消：
             * 锁定保证金从 balance_in_virtual_order 释放回虚拟交易账户 balance_in_virtual_trade。
             */
            $this->moveWalletAmount(
                $wallet,
                'balance_in_virtual_order',
                'balance_in_virtual_trade',
                $releaseAmount
            );
        }
    }

    private function settleFuturesVirtualMargin($future, Wallet $wallet, $releasedAmountAfterFee, bool $isLiquidated = false): array
    {
        $releasedAmountAfterFee = $this->safeDecimal($releasedAmountAfterFee);

        /*
         * 当前仓位锁定的虚拟保证金。
         */
        $lockedAmount = $this->safeDecimal(
            $future->trade_margin_amount ?? $future->total_margin_amount ?? 0
        );

        if (!$this->walletColumnExists('balance_in_virtual_order') || !$this->walletColumnExists('balance_in_virtual_trade')) {
            return [
                'trade_released_amount' => '0',
                'auto_invest_released_amount' => '0',
                'auto_invest_consumed_amount' => '0',
            ];
        }

        $wallet->refresh();

        $virtualOrderBalance = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0);

        /*
         * 如果仓位记录没有锁定金额，或者锁定金额大于当前虚拟挂单余额，
         * 就以当前 balance_in_virtual_order 为准，避免扣成负数。
         */
        if (
            $this->safeCompare($lockedAmount, 0) <= 0 ||
            $this->safeCompare($lockedAmount, $virtualOrderBalance) > 0
        ) {
            $lockedAmount = $virtualOrderBalance;
        }

        /*
         * 先扣掉虚拟合约锁定保证金。
         */
        if ($this->safeCompare($lockedAmount, 0) > 0) {
            $this->decreaseWalletField(
                $wallet,
                'balance_in_virtual_order',
                $lockedAmount,
                false
            );
        }

        /*
         * 平仓后释放金额全部回到虚拟交易账户。
         * 包含：本金剩余 + 盈利 - 平仓手续费。
         * 注意：这里不是 balance_in_virtual_wallet。
         */
        if ($this->safeCompare($releasedAmountAfterFee, 0) > 0) {
            $this->increaseWalletField(
                $wallet,
                'balance_in_virtual_trade',
                $releasedAmountAfterFee
            );
        }

        $consumedAmount = '0';

        if ($this->safeCompare($lockedAmount, $releasedAmountAfterFee) > 0) {
            $consumedAmount = $this->safeDecimal(
                math_sub($lockedAmount, $releasedAmountAfterFee)
            );
        }

        return [
            /*
             * 字段名沿用 trade_released_amount。
             * 虚拟仓位实际已经回到 balance_in_virtual_trade。
             */
            'trade_released_amount' => $releasedAmountAfterFee,
            'auto_invest_released_amount' => '0',
            'auto_invest_consumed_amount' => $consumedAmount,
        ];
    }

    private function increaseWalletField(Wallet $wallet, string $field, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        $allowedFields = [
            'balance_in_wallet',
            'balance_in_trade',
            'balance_in_order',
            'balance_in_withdraw',
            'balance_in_lc',
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
        ];

        if (!in_array($field, $allowedFields, true) || !$this->walletColumnExists($field)) {
            throw new \Exception('Invalid wallet balance field');
        }

        DB::statement(
            "UPDATE wallets SET {$field} = COALESCE({$field}, 0) + ?, updated_at = ? WHERE id = ?",
            [$amount, Carbon::now(), $wallet->id]
        );

        $wallet->refresh();
    }

    private function decreaseWalletField(Wallet $wallet, string $field, $amount, bool $strict = true): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        $allowedFields = [
            'balance_in_wallet',
            'balance_in_trade',
            'balance_in_order',
            'balance_in_withdraw',
            'balance_in_lc',
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
        ];

        if (!in_array($field, $allowedFields, true) || !$this->walletColumnExists($field)) {
            throw new \Exception('Invalid wallet balance field');
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            throw new \Exception('Wallet not found');
        }

        $available = $this->safeDecimal($freshWallet->{$field} ?? 0);

        if ($strict && $this->safeCompare($available, $amount) < 0) {
            throw new \Exception('Insufficient balance on ' . $field . '. Available: ' . $available . ', Required: ' . $amount);
        }

        $deductAmount = $this->safeCompare($available, $amount) >= 0 ? $amount : $available;

        if ($this->safeCompare($deductAmount, 0) <= 0) {
            return;
        }

        DB::statement(
            "UPDATE wallets SET {$field} = GREATEST(COALESCE({$field}, 0) - ?, 0), updated_at = ? WHERE id = ?",
            [$deductAmount, Carbon::now(), $wallet->id]
        );

        $wallet->refresh();
    }

    private function allocateFuturesMargin($wallet, int $userId, int $currencyId, $requiredAmount, string $sourceId, string $sourceStatus = 'active'): array
    {
        $requiredAmount = $this->safeDecimal($requiredAmount);

        if ($this->safeCompare($requiredAmount, 0) <= 0) {
            throw new \Exception('Invalid margin amount');
        }

        $tradeBalance = $this->safeDecimal($wallet->balance_in_trade ?? 0);

        /**
         * 交易账户资金优先使用。
         */
        $tradeUseAmount = $this->safeCompare($tradeBalance, $requiredAmount) >= 0
            ? $requiredAmount
            : $tradeBalance;

        $remainingAmount = $this->safeDecimal(math_sub($requiredAmount, $tradeUseAmount));

        /**
         * 先扣交易账户真实可用资金。
         */
        if ($this->safeCompare($tradeUseAmount, 0) > 0) {
            $this->walletService->decrease($wallet, $tradeUseAmount, 'trade');
        }

        $autoInvestUseAmount = '0';

        if ($this->safeCompare($remainingAmount, 0) > 0) {
            $autoInvestUseAmount = $this->lockAutoInvestMargin(
                $userId,
                $currencyId,
                $remainingAmount,
                $sourceId
            );
        }

        $allocatedTotal = $this->safeDecimal(math_sum($tradeUseAmount, $autoInvestUseAmount));

        if ($this->safeCompare($allocatedTotal, $requiredAmount) < 0) {
            /**
             * 理财也不够时，回滚前面扣掉的交易账户资金，并释放本次已经锁定的理财。
             */
            if ($this->safeCompare($tradeUseAmount, 0) > 0) {
                $this->walletService->increase($wallet, $tradeUseAmount, 'trade');
            }

            if ($this->safeCompare($autoInvestUseAmount, 0) > 0) {
                $this->releaseAutoInvestMarginLocks($sourceId);
            }

            throw new \Exception(
                'Insufficient futures balance. Trade used: ' . $tradeUseAmount .
                ', Auto Invest locked: ' . $autoInvestUseAmount .
                ', Required: ' . $requiredAmount
            );
        }

        return [
            'trade_margin_amount' => $this->safeDecimal($tradeUseAmount),
            'auto_invest_margin_amount' => $this->safeDecimal($autoInvestUseAmount),
            'total_margin_amount' => $this->safeDecimal($allocatedTotal),
            'source_status' => $sourceStatus,
        ];
    }
private function lockAutoInvestMargin(int $userId, int $currencyId, $requiredAmount, string $sourceId): string
{
    /*
     * $currencyId 是当前合约计价币种，通常是 USDT。
     * $requiredAmount 也是这个计价币种的保证金数量。
     *
     * 理财订单可能是 BTC/ETH/OKR/USDT：
     * 这里会按 started_at 从最早订单开始扣，
     * 并把每笔理财订单折算成当前合约计价币种后参与保证金。
     */
    $requiredAmount = $this->safeDecimal($requiredAmount);
    $lockedQuoteAmount = '0';

    if ($this->safeCompare($requiredAmount, 0) <= 0) {
        return '0';
    }

    if (!$this->schemaHasTableCached('auto_invest_orders')) {
        return '0';
    }

    /*
     * 这里不能静默跳过 auto_invest_margin_locks。
     * 如果没有锁表，即使算到了理财金额，也无法在平仓/爆仓时恢复 used_margin。
     */
    if (!$this->schemaHasTableCached('auto_invest_margin_locks')) {
        throw new \Exception('Auto invest margin locks table not found. Please run the migration first.');
    }

    if (!$this->schemaHasColumnCached('auto_invest_orders', 'used_margin')) {
        throw new \Exception('auto_invest_orders.used_margin column not found.');
    }

    $selectColumns = ['id', 'currency_id', 'amount', 'used_margin'];
    $hasStartedAt = $this->schemaHasColumnCached('auto_invest_orders', 'started_at');
    $hasCreatedAt = $this->schemaHasColumnCached('auto_invest_orders', 'created_at');

    if ($hasStartedAt) {
        $selectColumns[] = 'started_at';
    }

    if ($hasCreatedAt) {
        $selectColumns[] = 'created_at';
    }

    $ordersQuery = DB::table('auto_invest_orders')
        ->select($selectColumns)
        ->where('user_id', $userId)
        ->where('status', 'active')
        /*
         * used_margin 可能是 NULL。
         * 如果直接 amount - used_margin，会得到 NULL，导致 active 理财订单被过滤掉。
         */
        ->whereRaw('(COALESCE(amount, 0) - COALESCE(used_margin, 0)) > 0')
        ->lockForUpdate();

    if ($hasStartedAt && $hasCreatedAt) {
        $ordersQuery->orderByRaw('COALESCE(started_at, created_at) ASC');
    } elseif ($hasStartedAt) {
        $ordersQuery->orderBy('started_at');
    } elseif ($hasCreatedAt) {
        $ordersQuery->orderBy('created_at');
    }

    $orders = $ordersQuery->orderBy('id')->get();

    foreach ($orders as $order) {
        if ($this->safeCompare($lockedQuoteAmount, $requiredAmount) >= 0) {
            break;
        }

        $orderCurrencyId = (int) ($order->currency_id ?? 0);

        if ($orderCurrencyId <= 0) {
            continue;
        }

        $availableOrderAmount = $this->safeDecimal(
            math_sub($order->amount ?? 0, $order->used_margin ?? 0)
        );

        if ($this->safeCompare($availableOrderAmount, 0) <= 0) {
            continue;
        }

        $availableQuoteAmount = $this->convertAutoInvestAmountToQuoteCurrency(
            $availableOrderAmount,
            $orderCurrencyId,
            $currencyId
        );

        if ($this->safeCompare($availableQuoteAmount, 0) <= 0) {
            throw new \Exception(
                'Auto invest order #' . $order->id . ' is earlier by started_at but cannot be converted to the futures quote currency. Please check its currency price.'
            );
        }

        $needQuoteAmount = $this->safeDecimal(
            math_sub($requiredAmount, $lockedQuoteAmount)
        );

        $useQuoteAmount = $this->safeCompare($availableQuoteAmount, $needQuoteAmount) >= 0
            ? $needQuoteAmount
            : $availableQuoteAmount;

        $useOrderAmount = $this->convertQuoteAmountToAutoInvestCurrency(
            $useQuoteAmount,
            $currencyId,
            $orderCurrencyId
        );

        /*
         * 避免汇率/小数误差导致扣超过该订单可用数量。
         */
        if ($this->safeCompare($useOrderAmount, $availableOrderAmount) > 0) {
            $useOrderAmount = $availableOrderAmount;
            $useQuoteAmount = $this->convertAutoInvestAmountToQuoteCurrency(
                $useOrderAmount,
                $orderCurrencyId,
                $currencyId
            );
        }

        if ($this->safeCompare($useOrderAmount, 0) <= 0 || $this->safeCompare($useQuoteAmount, 0) <= 0) {
            throw new \Exception(
                'Auto invest order #' . $order->id . ' is earlier by started_at but produced an invalid margin amount. Please check its currency price.'
            );
        }

        DB::table('auto_invest_orders')
            ->where('id', $order->id)
            ->update([
                'used_margin' => DB::raw('COALESCE(used_margin, 0) + ' . $this->safeDecimal($useOrderAmount)),
                'updated_at' => now(),
            ]);

        /*
         * amount 保存原理财币种数量。
         * 比如 BTC 理财用于 USDT 合约时，这里 amount 存 BTC 数量，
         * 结算时再按该币种实时折算为合约计价币种处理。
         */
        $this->insertAutoInvestMarginLock([
            'auto_invest_order_id' => $order->id,
            'user_id' => $userId,
            'currency_id' => $orderCurrencyId,
            'source_type' => 'futures',
            'source_id' => $sourceId,
            'amount' => $this->safeDecimal($useOrderAmount),
            'released_amount' => 0,
            'consumed_amount' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lockedQuoteAmount = $this->safeDecimal(
            math_sum($lockedQuoteAmount, $useQuoteAmount)
        );
    }

    return $this->safeDecimal($lockedQuoteAmount);
}


    private function releaseAutoInvestMarginLocks(string $sourceId, bool $closeAutoInvestOrders = false): void
    {
        if (!$this->schemaHasTableCached('auto_invest_margin_locks')) {
            return;
        }

        $locks = DB::table('auto_invest_margin_locks')
            ->where('source_type', 'futures')
            ->where('source_id', $sourceId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        foreach ($locks as $lock) {
            $amount = $this->safeDecimal($lock->amount ?? 0);

            if ($this->safeCompare($amount, 0) <= 0) {
                continue;
            }

            $orderUpdate = [
                'used_margin' => DB::raw('GREATEST(COALESCE(used_margin, 0) - ' . $amount . ', 0)'),
                'updated_at' => now(),
            ];

            /**
             * 这里只释放 used_margin，不直接关闭理财订单。
             * 是否关闭由 syncAutoInvestOrdersAfterFuturesSettlement() 按 amount <= 0 兜底判断，
             * 避免爆仓只消耗部分理财时，把仍有余额的订单错误关闭。
             */

            DB::table('auto_invest_orders')
                ->where('id', $lock->auto_invest_order_id)
                ->update($orderUpdate);

            DB::table('auto_invest_margin_locks')
                ->where('id', $lock->id)
                ->update([
                    'released_amount' => $amount,
                    'status' => 'released',
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * 平仓 / 爆仓资金结算。
     *
     * releasedAmountAfterFee 是本仓位平仓后剩余资金。
     *
     * 规则：
     * 1. 理财质押部分优先恢复。
     * 2. 超过理财质押的部分进入交易账户。
     * 3. 如果不足以恢复理财质押，差额从理财订单 amount 扣掉。
     * 4. 爆仓时 releasedAmountAfterFee = 0，则本仓位占用的理财金额全部扣掉。
     */
private function settleFuturesMarginByAutoInvestRule($future, $wallet, $releasedAmountAfterFee, bool $isLiquidated = false, string $tradeReleaseAccount = 'trade'): array
{
	$releasedAmountAfterFee = $this->safeDecimal($releasedAmountAfterFee);
	$autoInvestLockedAmount = $this->safeDecimal($future->auto_invest_margin_amount ?? 0);
	$shouldCreditVirtualTrade = $this->shouldCreditFuturesVirtualWallet($future, $wallet);
	$quoteCurrencyId = (int) ($future->quote_currency_id ?? $wallet->currency_id ?? 0);

    $activeLockQuoteAmount = $this->getActiveAutoInvestLockedQuoteAmount(
        (string) ($future->id ?? ''),
        $quoteCurrencyId
    );

    if ($this->safeCompare($activeLockQuoteAmount, $autoInvestLockedAmount) > 0) {
        $autoInvestLockedAmount = $activeLockQuoteAmount;
    }

    /*
     * 没有使用理财质押：
     * 平仓剩余金额只能回交易账户。
     * 虚拟账户回 balance_in_virtual_trade，真实账户回 balance_in_trade。
     */
	if ($this->safeCompare($autoInvestLockedAmount, 0) <= 0) {
		if ($this->safeCompare($releasedAmountAfterFee, 0) > 0) {
			if ($shouldCreditVirtualTrade) {
				$this->increaseWalletField($wallet, 'balance_in_virtual_trade', $releasedAmountAfterFee);
			} else {
				$this->walletService->increase($wallet, $releasedAmountAfterFee, 'trade');
            }
        }

        return [
            'trade_released_amount' => $releasedAmountAfterFee,
            'auto_invest_released_amount' => '0',
            'auto_invest_consumed_amount' => '0',
        ];
    }

    /*
     * 平仓结果大于或等于理财占用：
     * 1. 理财占用全部释放。
     * 2. 多出的部分回交易账户。
     * 3. 虚拟账户多出部分回 balance_in_virtual_trade。
     */
    if ($this->safeCompare($releasedAmountAfterFee, $autoInvestLockedAmount) >= 0) {
        $this->releaseAutoInvestMarginLocks(
            (string) $future->id,
            $isLiquidated
        );

        $tradeReleaseAmount = $this->safeDecimal(
            math_sub($releasedAmountAfterFee, $autoInvestLockedAmount)
        );

	    if ($this->safeCompare($tradeReleaseAmount, 0) > 0) {
	        if ($shouldCreditVirtualTrade) {
	            $this->increaseWalletField($wallet, 'balance_in_virtual_trade', $tradeReleaseAmount);
	        } elseif ($tradeReleaseAccount === 'wallet') {
                $this->walletService->increase($wallet, $tradeReleaseAmount, 'wallet');
            } else {
                $this->walletService->increase($wallet, $tradeReleaseAmount, 'trade');
            }
        }

        return [
            'trade_released_amount' => $tradeReleaseAmount,
            'auto_invest_released_amount' => $autoInvestLockedAmount,
            'auto_invest_consumed_amount' => '0',
        ];
    }

    /*
     * 平仓结果不足以恢复理财：
     * 1. 不回交易账户。
     * 2. releasedAmountAfterFee 会优先恢复到最早锁定的理财订单。
     * 3. 不足部分从对应理财订单 amount 扣除。
     * 4. 非 USDT 理财会先折算为当前合约计价币种再判断消耗。
     */
    $autoInvestRemainingAmount = $releasedAmountAfterFee;
    $autoInvestConsumedAmount = $this->safeDecimal(
        math_sub($autoInvestLockedAmount, $releasedAmountAfterFee)
    );

    $this->consumeAutoInvestMarginLocks(
        (string) $future->id,
        $autoInvestRemainingAmount,
        $isLiquidated,
        $quoteCurrencyId
    );

    return [
        'trade_released_amount' => '0',
        'auto_invest_released_amount' => $autoInvestRemainingAmount,
        'auto_invest_consumed_amount' => $autoInvestConsumedAmount,
    ];
}

private function hasActiveAutoInvestMarginLocks(string $sourceId): bool
{
    if ($sourceId === '' || !$this->schemaHasTableCached('auto_invest_margin_locks')) {
        return false;
    }

    return DB::table('auto_invest_margin_locks')
        ->where('source_type', 'futures')
        ->where('source_id', $sourceId)
        ->where('status', 'active')
        ->exists();
}

private function getActiveAutoInvestLockedQuoteAmount(string $sourceId, int $quoteCurrencyId): string
{
    if (
        $sourceId === '' ||
        $quoteCurrencyId <= 0 ||
        !$this->schemaHasTableCached('auto_invest_margin_locks')
    ) {
        return '0';
    }

    $locks = DB::table('auto_invest_margin_locks')
        ->where('source_type', 'futures')
        ->where('source_id', $sourceId)
        ->where('status', 'active')
        ->lockForUpdate()
        ->get();

    $lockedQuoteAmount = '0';

    foreach ($locks as $lock) {
        $lockCurrencyId = (int) ($lock->currency_id ?? 0);
        $lockAmount = $this->safeDecimal($lock->amount ?? 0);

        if ($lockCurrencyId <= 0 || $this->safeCompare($lockAmount, 0) <= 0) {
            continue;
        }

        $lockedQuoteAmount = $this->safeDecimal(math_sum(
            $lockedQuoteAmount,
            $this->convertAutoInvestAmountToQuoteCurrency($lockAmount, $lockCurrencyId, $quoteCurrencyId)
        ));
    }

    return $lockedQuoteAmount;
}

    /**
     * 亏损 / 爆仓时处理理财占用。
     *
     * settlementForAutoInvest 表示平仓后还能留给理财恢复的金额。
     * 如果为 0，则该仓位占用的理财全部被亏损吃掉。
     */
private function consumeAutoInvestMarginLocks(string $sourceId, $settlementForAutoInvest, bool $closeAutoInvestOrders = false, ?int $quoteCurrencyId = null): void
{
    if (!$this->schemaHasTableCached('auto_invest_margin_locks')) {
        return;
    }

    if (!$quoteCurrencyId || $quoteCurrencyId <= 0) {
        $quoteCurrencyId = (int) FuturesContract::where('id', $sourceId)->value('quote_currency_id');
    }

    $remainingQuoteForAutoInvest = $this->safeDecimal($settlementForAutoInvest);

    $hasOrderStartedAt = $this->schemaHasColumnCached('auto_invest_orders', 'started_at');
    $hasOrderCreatedAt = $this->schemaHasColumnCached('auto_invest_orders', 'created_at');

    $locksQuery = DB::table('auto_invest_margin_locks')
        ->select('auto_invest_margin_locks.*')
        ->join('auto_invest_orders as aio', 'aio.id', '=', 'auto_invest_margin_locks.auto_invest_order_id')
        ->where('auto_invest_margin_locks.source_type', 'futures')
        ->where('auto_invest_margin_locks.source_id', $sourceId)
        ->where('auto_invest_margin_locks.status', 'active');

    if ($hasOrderStartedAt && $hasOrderCreatedAt) {
        $locksQuery->orderByRaw('COALESCE(aio.started_at, aio.created_at, auto_invest_margin_locks.created_at) ASC');
    } elseif ($hasOrderStartedAt) {
        $locksQuery->orderBy('aio.started_at');
    } elseif ($hasOrderCreatedAt) {
        $locksQuery->orderBy('aio.created_at');
    } else {
        $locksQuery->orderBy('auto_invest_margin_locks.created_at');
    }

    $locks = $locksQuery
        ->orderByRaw('COALESCE(aio.id, auto_invest_margin_locks.auto_invest_order_id) ASC')
        ->orderBy('auto_invest_margin_locks.id')
        ->lockForUpdate()
        ->get();

    foreach ($locks as $lock) {
        $lockCurrencyId = (int) ($lock->currency_id ?? 0);
        $lockAmount = $this->safeDecimal($lock->amount ?? 0);

        if ($lockCurrencyId <= 0 || $this->safeCompare($lockAmount, 0) <= 0) {
            continue;
        }

        /*
         * lockAmount 是原理财币种数量。
         * 先折算成当前合约计价币种，才能和 settlementForAutoInvest 比较。
         */
        $lockQuoteAmount = $this->convertAutoInvestAmountToQuoteCurrency(
            $lockAmount,
            $lockCurrencyId,
            $quoteCurrencyId
        );

        if ($this->safeCompare($lockQuoteAmount, 0) <= 0) {
            continue;
        }

        $restoreQuoteAmount = '0';

        if ($this->safeCompare($remainingQuoteForAutoInvest, 0) > 0) {
            $restoreQuoteAmount = $this->safeCompare($remainingQuoteForAutoInvest, $lockQuoteAmount) >= 0
                ? $lockQuoteAmount
                : $remainingQuoteForAutoInvest;

            $remainingQuoteForAutoInvest = $this->safeDecimal(
                math_sub($remainingQuoteForAutoInvest, $restoreQuoteAmount)
            );
        }

        $restoreOrderAmount = '0';

        if ($this->safeCompare($restoreQuoteAmount, 0) > 0) {
            $restoreOrderAmount = $this->convertQuoteAmountToAutoInvestCurrency(
                $restoreQuoteAmount,
                $quoteCurrencyId,
                $lockCurrencyId
            );

            if ($this->safeCompare($restoreOrderAmount, $lockAmount) > 0) {
                $restoreOrderAmount = $lockAmount;
            }
        }

        /*
         * 被亏损吃掉的原理财币种数量。
         */
        $consumedOrderAmount = $this->safeDecimal(
            math_sub($lockAmount, $restoreOrderAmount)
        );

        if ($this->safeCompare($consumedOrderAmount, 0) < 0) {
            $consumedOrderAmount = '0';
        }

        $autoInvestOrder = DB::table('auto_invest_orders')
            ->where('id', $lock->auto_invest_order_id)
            ->lockForUpdate()
            ->first();

        if (!$autoInvestOrder) {
            continue;
        }

        $currentAmount = $this->safeDecimal($autoInvestOrder->amount ?? 0);
        $newAmount = $this->safeDecimal(
            math_sub($currentAmount, $consumedOrderAmount)
        );

        if ($this->safeCompare($newAmount, 0) < 0) {
            $newAmount = '0';
        }

        /*
         * 当前锁定已经结算，used_margin 必须释放掉原币种 lockAmount。
         * 只有 consumedOrderAmount 才真正减少理财订单 amount。
         */
        $orderUpdate = [
            'used_margin' => DB::raw('GREATEST(COALESCE(used_margin, 0) - ' . $this->safeDecimal($lockAmount) . ', 0)'),
            'amount' => $newAmount,
            'updated_at' => now(),
        ];

        $shouldCloseOrder = $this->safeCompare($newAmount, 0) <= 0;

        $orderUpdate = array_merge(
            $orderUpdate,
            $this->getAutoInvestOrderCloseUpdateFields($shouldCloseOrder, $closeAutoInvestOrders ? 'liquidated_by_futures' : 'closed_by_futures_loss')
        );

        DB::table('auto_invest_orders')
            ->where('id', $lock->auto_invest_order_id)
            ->update($orderUpdate);

        DB::table('auto_invest_margin_locks')
            ->where('id', $lock->id)
            ->update([
                'released_amount' => $restoreOrderAmount,
                'consumed_amount' => $consumedOrderAmount,
                'status' => $this->safeCompare($consumedOrderAmount, 0) > 0 ? 'consumed' : 'released',
                'released_at' => $this->safeCompare($restoreOrderAmount, 0) > 0 ? now() : null,
                'consumed_at' => $this->safeCompare($consumedOrderAmount, 0) > 0 ? now() : null,
                'updated_at' => now(),
            ]);
    }
}

    /**
     * 平仓 / 爆仓后同步理财订单状态。
     *
     * 规则：
     * 1. 根据当前仍然 active 的 margin_locks 重新计算 used_margin。
     * 2. 如果理财订单 amount <= 0，则 status 改成 closed。
     * 3. 如果 amount > 0，则继续 active，剩余资金继续作为理财本金参与收益。
     */
    private function syncAutoInvestOrdersAfterFuturesSettlement(string $sourceId, bool $isLiquidated = false): void
    {
        if (
            !$this->schemaHasTableCached('auto_invest_orders') ||
            !$this->schemaHasTableCached('auto_invest_margin_locks')
        ) {
            return;
        }

        $autoInvestOrderIds = DB::table('auto_invest_margin_locks')
            ->where('source_type', 'futures')
            ->where('source_id', $sourceId)
            ->pluck('auto_invest_order_id')
            ->filter()
            ->unique()
            ->values();

        if ($autoInvestOrderIds->isEmpty()) {
            return;
        }

        foreach ($autoInvestOrderIds as $autoInvestOrderId) {
            $autoInvestOrder = DB::table('auto_invest_orders')
                ->where('id', $autoInvestOrderId)
                ->lockForUpdate()
                ->first();

            if (!$autoInvestOrder) {
                continue;
            }

            /**
             * 同一笔理财订单可能被多个合约仓位同时占用。
             * 当前仓位平仓/爆仓后，used_margin 必须等于剩余 active locks 的合计。
             */
            $activeUsedMargin = DB::table('auto_invest_margin_locks')
                ->where('auto_invest_order_id', $autoInvestOrderId)
                ->where('status', 'active')
                ->sum('amount');

            $amount = $this->safeDecimal($autoInvestOrder->amount ?? 0);

            $update = [
                'used_margin' => $this->safeDecimal($activeUsedMargin),
                'updated_at' => now(),
            ];

            /**
             * 核心兜底：
             * 爆仓或亏损后，如果 amount 已经被扣到 0，不能再保持 active。
             * 如果 amount 仍然大于 0，说明只是部分理财被亏损消耗，订单继续 active。
             */
            if ($this->safeCompare($amount, 0) <= 0) {
                $update = array_merge(
                    $update,
                    $this->getAutoInvestOrderCloseUpdateFields(true, $isLiquidated ? 'liquidated_by_futures' : 'closed_by_futures_loss')
                );

                $update['used_margin'] = 0;

                DB::table('auto_invest_margin_locks')
                    ->where('auto_invest_order_id', $autoInvestOrderId)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'consumed',
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('auto_invest_orders')
                ->where('id', $autoInvestOrderId)
                ->update($update);
        }
    }

    private function getAutoInvestOrderCloseUpdateFields(bool $shouldClose, string $reason = 'closed'): array
    {
        if (!$shouldClose || !$this->schemaHasTableCached('auto_invest_orders')) {
            return [];
        }

        $updates = [];

        if ($this->schemaHasColumnCached('auto_invest_orders', 'status')) {
            $updates['status'] = 'closed';
        }

        if ($this->schemaHasColumnCached('auto_invest_orders', 'closed_at')) {
            $updates['closed_at'] = now();
        }

        if ($this->schemaHasColumnCached('auto_invest_orders', 'ended_at')) {
            $updates['ended_at'] = now();
        }

        if ($this->schemaHasColumnCached('auto_invest_orders', 'completed_at')) {
            $updates['completed_at'] = now();
        }

        if ($this->schemaHasColumnCached('auto_invest_orders', 'finished_at')) {
            $updates['finished_at'] = now();
        }

        if ($this->schemaHasColumnCached('auto_invest_orders', 'close_reason')) {
            $updates['close_reason'] = $reason;
        }

        if ($this->schemaHasColumnCached('auto_invest_orders', 'end_reason')) {
            $updates['end_reason'] = $reason;
        }

        return $updates;
    }
    private function isFuturesLiquidatedByPrice($future, $marketPrice): bool
    {
        if (!$future) {
            return false;
        }

        $precision = 8;

        if ($future->relationLoaded('market') && $future->market) {
            $precision = (int) $future->market->quote_precision;
        } elseif (!empty($future->market_id)) {
            $marketPrecision = Market::where('id', $future->market_id)->value('quote_precision');

            if ($marketPrecision !== null) {
                $precision = (int) $marketPrecision;
            }
        }

        /**
         * 使用有效爆仓价，而不是旧的 liquidation_price。
         * 有效爆仓价包含理财质押保证金，避免提前爆仓。
         */
        $liquidationPrice = $this->getFuturesEffectiveLiquidationPrice($future);
        $marketPrice = $this->safeDecimal($marketPrice, $precision);

        if (
            $this->safeCompare($liquidationPrice, 0, $precision) <= 0 ||
            $this->safeCompare($marketPrice, 0, $precision) <= 0
        ) {
            return false;
        }

        /**
         * 多单：当前价 <= 有效爆仓价
         */
        if ((bool) $future->is_long) {
            return $this->safeCompare($marketPrice, $liquidationPrice, $precision) <= 0;
        }

        /**
         * 空单：当前价 >= 有效爆仓价
         */
        return $this->safeCompare($marketPrice, $liquidationPrice, $precision) >= 0;
    }

    private function getFuturesEffectiveLiquidationPrice($future): string
    {
        if (!$future) {
            return '0';
        }

        $precision = 8;

        if ($future->relationLoaded('market') && $future->market) {
            $precision = (int) $future->market->quote_precision;
        } elseif (!empty($future->market_id)) {
            $marketPrecision = Market::where('id', $future->market_id)->value('quote_precision');

            if ($marketPrecision !== null) {
                $precision = (int) $marketPrecision;
            }
        }

        /*
         * 虚拟保证金也要进入爆仓检测。
         */
        $virtualMarginAmount = $this->getFuturesVirtualMarginAmount($future);

        $calculatedPrice = $this->calculateFuturesLiquidationPriceByMargin(
            $future->price ?? 0,
            $future->quantity ?? 0,
            $future->balance ?? 0,
            (bool) $future->is_long,
            $precision,
            [
                'entry_fee' => $future->entry_fee ?? 0,
                'trade_margin_amount' => $future->trade_margin_amount ?? 0,
                'auto_invest_margin_amount' => $future->auto_invest_margin_amount ?? 0,
                'total_margin_amount' => $future->total_margin_amount ?? 0,
                'virtual_margin_amount' => $virtualMarginAmount,
                'is_virtual_position' => $this->safeCompare($virtualMarginAmount, 0, 18) > 0,
            ]
        );

        if ($this->safeCompare($calculatedPrice, 0, $precision) > 0) {
            return $calculatedPrice;
        }

        return $this->safeDecimal($future->liquidation_price ?? 0, $precision);
    }

    private function getFuturesVirtualMarginAmount($future): string
    {
        if (!$future) {
            return '0';
        }

        $autoInvestMargin = $this->safeDecimal($future->auto_invest_margin_amount ?? 0, 18);
        $tradeMargin = $this->safeDecimal($future->trade_margin_amount ?? 0, 18);
        $totalMargin = $this->safeDecimal($future->total_margin_amount ?? 0, 18);

        /*
         * 使用理财质押的仓位，不按虚拟仓位处理。
         */
        if ($this->safeCompare($autoInvestMargin, 0, 18) > 0) {
            return '0';
        }

        $wallet = null;

        if (!empty($future->user_id) && !empty($future->quote_currency_id)) {
            $wallet = $this->getOrCreateWalletByCurrency(
                $future->user_id,
                $future->quote_currency_id,
                false
            );
        }

        $virtualOrderBalance = '0';

        if ($wallet && $this->walletColumnExists('balance_in_virtual_order')) {
            $virtualOrderBalance = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0, 18);
        }

        if ($this->safeCompare($virtualOrderBalance, 0, 18) > 0) {
            if ($this->safeCompare($tradeMargin, 0, 18) > 0) {
                return $tradeMargin;
            }

            if ($this->safeCompare($totalMargin, 0, 18) > 0) {
                return $totalMargin;
            }

            return $this->safeDecimal($future->balance ?? 0, 18);
        }

        if ($wallet) {
            $virtualTotal = '0';

            $virtualFields = [
                'balance_in_virtual_wallet',
                'balance_in_virtual_trade',
                'balance_in_virtual_order',
                'balance_in_virtual_withdraw',
            ];

            foreach ($virtualFields as $field) {
                if (!$this->walletColumnExists($field)) {
                    continue;
                }

                $virtualTotal = $this->safeDecimal(
                    math_sum($virtualTotal, $wallet->{$field} ?? 0),
                    18
                );
            }

            if ($this->safeCompare($virtualTotal, 0, 18) > 0) {
                if ($this->safeCompare($tradeMargin, 0, 18) > 0) {
                    return $tradeMargin;
                }

                if ($this->safeCompare($totalMargin, 0, 18) > 0) {
                    return $totalMargin;
                }

                return $this->safeDecimal($future->balance ?? 0, 18);
            }
        }

        return '0';
    }

    /**
     * 根据真实有效保证金计算爆仓价。
     *
     * 有效保证金优先使用：
     * trade_margin_amount + auto_invest_margin_amount - entry_fee
     *
     * 这样理财质押资金会参与爆仓价计算，不会出现提前爆仓。
     */
    private function calculateFuturesLiquidationPriceByMargin(
        $entryPrice,
        $quantity,
        $balance,
        bool $isLong,
        int $precision = 8,
        $futureOrData = null
    ): string {
        $entryPrice = $this->safeDecimal($entryPrice, $precision);
        $quantity = $this->safeDecimal($quantity, 18);
        $effectiveMargin = $this->safeDecimal($balance, 18);

        if (
            $this->safeCompare($entryPrice, 0, $precision) <= 0 ||
            $this->safeCompare($quantity, 0, 18) <= 0
        ) {
            return '0';
        }

        $entryFee = '0';
        $tradeMarginAmount = '0';
        $autoInvestMarginAmount = '0';
        $totalMarginAmount = '0';
        $virtualMarginAmount = '0';
        $isVirtualPosition = false;

        if (is_array($futureOrData)) {
            $entryFee = $this->safeDecimal($futureOrData['entry_fee'] ?? 0, 18);
            $tradeMarginAmount = $this->safeDecimal($futureOrData['trade_margin_amount'] ?? 0, 18);
            $autoInvestMarginAmount = $this->safeDecimal($futureOrData['auto_invest_margin_amount'] ?? 0, 18);
            $totalMarginAmount = $this->safeDecimal($futureOrData['total_margin_amount'] ?? 0, 18);
            $virtualMarginAmount = $this->safeDecimal($futureOrData['virtual_margin_amount'] ?? 0, 18);
            $isVirtualPosition = !empty($futureOrData['is_virtual_position']);
        } elseif ($futureOrData) {
            $entryFee = $this->safeDecimal($futureOrData->entry_fee ?? 0, 18);
            $tradeMarginAmount = $this->safeDecimal($futureOrData->trade_margin_amount ?? 0, 18);
            $autoInvestMarginAmount = $this->safeDecimal($futureOrData->auto_invest_margin_amount ?? 0, 18);
            $totalMarginAmount = $this->safeDecimal($futureOrData->total_margin_amount ?? 0, 18);

            $virtualMarginAmount = $this->getFuturesVirtualMarginAmount($futureOrData);
            $isVirtualPosition = $this->safeCompare($virtualMarginAmount, 0, 18) > 0;
        }

        /*
         * 来源保证金：
         *
         * 1. 虚拟仓位：使用 virtual_margin_amount。
         *    不再额外叠加 trade_margin_amount，避免重复计算。
         *
         * 2. 真实仓位：使用 trade_margin_amount + auto_invest_margin_amount。
         *
         * 3. 如果上面都没有，兜底 total_margin_amount。
         */
        if ($isVirtualPosition && $this->safeCompare($virtualMarginAmount, 0, 18) > 0) {
            $sourceMarginAmount = $virtualMarginAmount;
        } else {
            $sourceMarginAmount = $this->safeDecimal(
                math_sum($tradeMarginAmount, $autoInvestMarginAmount),
                18
            );
        }

        if ($this->safeCompare($sourceMarginAmount, 0, 18) <= 0) {
            $sourceMarginAmount = $totalMarginAmount;
        }

        /*
         * 扣掉入场手续费后的实际可承受亏损保证金。
         * 虚拟仓位也是：虚拟保证金 - 入场手续费。
         */
        if ($this->safeCompare($sourceMarginAmount, 0, 18) > 0) {
            $sourceNetMargin = $this->safeDecimal(
                math_sub($sourceMarginAmount, $entryFee),
                18
            );

            if ($this->safeCompare($sourceNetMargin, 0, 18) > 0) {
                if ($this->safeCompare($sourceNetMargin, $effectiveMargin, 18) > 0) {
                    $effectiveMargin = $sourceNetMargin;
                }
            }
        }

        if ($this->safeCompare($effectiveMargin, 0, 18) <= 0) {
            return '0';
        }

        $notionalValue = $this->safeDecimal(
            math_multiply($entryPrice, $quantity),
            18
        );

        $maintenanceMarginRate = $this->safeDecimal(
            Setting::get('futures.maintenance_margin_rate', 0),
            8
        );

        $maintenanceMarginAmount = '0';

        if ($this->safeCompare($maintenanceMarginRate, 0, 8) > 0) {
            $maintenanceMarginAmount = $this->safeDecimal(
                math_percentage($notionalValue, $maintenanceMarginRate),
                18
            );
        }

        $lossCapacity = $this->safeDecimal(
            math_sub($effectiveMargin, $maintenanceMarginAmount),
            18
        );

        if ($this->safeCompare($lossCapacity, 0, 18) <= 0) {
            return $this->safeDecimal($entryPrice, $precision);
        }

        $priceMove = $this->safeDecimal(
            math_divide($lossCapacity, $quantity),
            18
        );

        if ($isLong) {
            $liquidationPrice = $this->safeDecimal(
                math_sub($entryPrice, $priceMove),
                $precision
            );
        } else {
            $liquidationPrice = $this->safeDecimal(
                math_sum($entryPrice, $priceMove),
                $precision
            );
        }

        if ($this->safeCompare($liquidationPrice, 0, $precision) <= 0) {
            $liquidationPrice = $this->getPriceMinUnitForPrecision($precision);
        }

        return math_formatter($liquidationPrice, $precision);
    }

    private function getPriceMinUnitForPrecision(int $precision): string
    {
        if ($precision <= 0) {
            return '1';
        }

        return rtrim(rtrim(number_format(pow(10, -$precision), $precision, '.', ''), '0'), '.');
    }
    /**
     * 安全数字处理
     */
    private function safeDecimal($value, $scale = 18)
    {
        return \App\Support\Decimal::normalize($value, (int)$scale);
    }

    /**
     * 安全数字比较。
     * 返回值：-1 表示 left < right，0 表示相等，1 表示 left > right。
     */
    private function safeCompare($left, $right, $scale = 18): int
    {
        $left = $this->safeDecimal($left, $scale);
        $right = $this->safeDecimal($right, $scale);

        if (function_exists('bccomp')) {
            return bccomp($left, $right, $scale);
        }

        if (function_exists('math_compare')) {
            return math_compare($left, $right);
        }

        $leftFloat = (float) $left;
        $rightFloat = (float) $right;

        if (abs($leftFloat - $rightFloat) < pow(10, -max((int) $scale, 0))) {
            return 0;
        }

        return $leftFloat < $rightFloat ? -1 : 1;
    }

    /**
     * 获取用户指定币种钱包。
     *
     * 如果 wallets 表里没有 user_id + currency_id 记录，先自动创建一条钱包记录，
     * 再重新通过 WalletRepository 查询，确保后续扣款、冻结、返还逻辑拿到标准 Wallet 模型。
     */
    private function getOrCreateWalletByCurrency($userId, $currencyId, $lock = true)
    {
        $wallet = $this->walletRepository->getWalletByCurrency($userId, $currencyId, $lock);

        if ($wallet) {
            return $wallet;
        }

        try {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->where('currency_id', $currencyId)
                ->first();

            if (!$wallet) {
                $wallet = new Wallet();
                $wallet->user_id = $userId;
                $wallet->currency_id = $currencyId;
                $wallet->save();
            }

            return $this->walletRepository->getWalletByCurrency($userId, $currencyId, $lock);
        } catch (\Throwable $e) {
            Log::error('Create wallet failed when placing order', [
                'user_id' => $userId,
                'currency_id' => $currencyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 获取现货下单当前可使用的交易账户余额。
     *
     * 规则保持和 lockSpotOrderFunds() 一致：
     * 1. 如果虚拟交易账户 balance_in_virtual_trade > 0，只使用虚拟交易账户。
     * 2. 否则使用真实交易账户 balance_in_trade。
     * 3. 不读取资金账户 balance_in_wallet / balance_in_virtual_wallet。
     */
    private function getSpotAvailableOrderBalance(Wallet $wallet): string
    {
        $wallet->refresh();

        $virtualSourceField = $this->getSpotVirtualSourceField($wallet);

        if ($virtualSourceField) {
            return $this->safeDecimal($wallet->{$virtualSourceField} ?? 0);
        }

        return $this->safeDecimal($wallet->balance_in_trade ?? 0);
    }

    /** Reject insufficient funds without silently changing the user's requested order. */
    private function normalizeSpotOrderAmountByAvailableBalance(
        Wallet $wallet,
        bool $buySide,
        bool $isBuyMarket,
        &$quantity,
        &$quoteQuantity,
        &$price,
        &$fee,
        $feeRate,
        &$finalQuantity
    ): void {
        $available = $this->getSpotAvailableOrderBalance($wallet);
        if ($this->safeCompare($finalQuantity, 0) <= 0 || $this->safeCompare($finalQuantity, $available) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['quantity'=>__('Insufficient balance')]);
        }
    }

    /**
     * 现货下单锁定资金。
     *
     * 现货交易账户规则：
     * 1. 虚拟交易账户 balance_in_virtual_trade > 0 时，只扣 balance_in_virtual_trade。
     * 2. 虚拟交易账户余额不足时，直接抛出余额不足，不混用真实账户。
     * 3. 虚拟交易账户为 0 时，才扣真实交易账户 balance_in_trade。
     * 4. 不再读取 / 扣除 balance_in_virtual_wallet 或 balance_in_wallet 资金账户。
     */
    private function lockSpotOrderFunds(Wallet $wallet, $amount): array
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            throw new \Exception('Invalid order amount');
        }

        $virtualSourceField = $this->getSpotVirtualSourceField($wallet);

        if ($virtualSourceField) {
            $available = $this->safeDecimal($wallet->{$virtualSourceField} ?? 0);

            if ($this->safeCompare($available, $amount) < 0) {
                throw new \Exception('Insufficient virtual balance. Available: ' . $available . ', Required: ' . $amount);
            }

            $this->moveWalletAmount(
                $wallet,
                $virtualSourceField,
                'balance_in_virtual_order',
                $amount
            );

            return [
                'account_type' => 'virtual',
                'source_field' => $virtualSourceField,
                'order_field' => 'balance_in_virtual_order',
                'amount' => $amount,
            ];
        }

        $available = $this->safeDecimal($wallet->balance_in_trade ?? 0);

        if ($this->safeCompare($available, $amount) < 0) {
            throw new \Exception('Insufficient balance. Available: ' . $available . ', Required: ' . $amount);
        }

        $this->moveWalletAmount(
            $wallet,
            'balance_in_trade',
            'balance_in_order',
            $amount
        );

        return [
            'account_type' => 'real',
            'source_field' => 'balance_in_trade',
            'order_field' => 'balance_in_order',
            'amount' => $amount,
        ];
    }

    /**
     * 现货取消订单释放资金。
     *
     * 交易账户规则：
     * 1. 虚拟挂单 balance_in_virtual_order 释放回 balance_in_virtual_trade。
     * 2. 真实挂单 balance_in_order 释放回 balance_in_trade。
     * 3. 不释放到 balance_in_virtual_wallet / balance_in_wallet 资金账户。
     */
    private function releaseSpotOrderFunds(Wallet $wallet, $amount, ?Order $order = null): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        $preferredOrderField = null;
        $orderTable = $order ? $order->getTable() : (new Order())->getTable();

        /*
         * 新订单如果写入了资金来源字段，取消时必须按订单自己的来源释放。
         * 这样可以避免同一个钱包同时存在真实挂单和虚拟挂单时，取消真实订单却误释放虚拟余额。
         */
        if ($order) {
            try {
                if ($this->schemaHasColumnCached($orderTable, 'balance_order_field')) {
                    $value = trim((string) $order->getAttribute('balance_order_field'));

                    if (in_array($value, ['balance_in_virtual_order', 'balance_in_order'], true)) {
                        $preferredOrderField = $value;
                    }
                }
            } catch (\Throwable $e) {
                $preferredOrderField = null;
            }

            if (!$preferredOrderField) {
                foreach (['account_type', 'funding_account_type', 'wallet_account_type'] as $column) {
                    try {
                        if (!$this->schemaHasColumnCached($orderTable, $column)) {
                            continue;
                        }

                        $accountType = trim((string) $order->getAttribute($column));

                        if ($accountType === 'virtual') {
                            $preferredOrderField = 'balance_in_virtual_order';
                            break;
                        }

                        if ($accountType === 'real') {
                            $preferredOrderField = 'balance_in_order';
                            break;
                        }
                    } catch (\Throwable $e) {

                    }
                }
            }
        }

        $releasePairs = [];
        $strictByOrderSource = false;

        if ($preferredOrderField === 'balance_in_virtual_order') {
            $releasePairs[] = [
                'from' => 'balance_in_virtual_order',
                'to' => 'balance_in_virtual_trade',
            ];
            $strictByOrderSource = true;
        } elseif ($preferredOrderField === 'balance_in_order') {
            $releasePairs[] = [
                'from' => 'balance_in_order',
                'to' => 'balance_in_trade',
            ];
            $strictByOrderSource = true;
        } else {
            /*
             * 旧订单没有资金来源字段时，保留兼容逻辑：
             * 先释放虚拟挂单，再释放真实挂单。
             */
            $releasePairs[] = [
                'from' => 'balance_in_virtual_order',
                'to' => 'balance_in_virtual_trade',
            ];
            $releasePairs[] = [
                'from' => 'balance_in_order',
                'to' => 'balance_in_trade',
            ];
        }

        $remaining = $amount;

        foreach ($releasePairs as $pair) {
            if ($this->safeCompare($remaining, 0) <= 0) {
                break;
            }

            $fromField = $pair['from'];
            $toField = $pair['to'];

            if (!$this->walletColumnExists($fromField) || !$this->walletColumnExists($toField)) {
                if ($strictByOrderSource) {
                    throw new \Exception('Wallet release field does not exist: ' . $fromField . ' -> ' . $toField);
                }

                continue;
            }

            $freshWallet = Wallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->first();

            if (!$freshWallet) {
                throw new \Exception('Wallet not found when releasing order funds');
            }

            $available = $this->safeDecimal($freshWallet->{$fromField} ?? 0);

            if ($this->safeCompare($available, 0) <= 0) {
                if ($strictByOrderSource) {
                    throw new \Exception('No locked order balance on ' . $fromField . ' to release');
                }

                continue;
            }

            if ($strictByOrderSource && $this->safeCompare($available, $remaining) < 0) {
                throw new \Exception('Locked order balance is not enough on ' . $fromField . '. Available: ' . $available . ', Required: ' . $remaining);
            }

            $releaseAmount = $this->safeCompare($available, $remaining) >= 0
                ? $remaining
                : $available;

            if ($this->safeCompare($releaseAmount, 0) > 0) {
                $this->moveWalletAmount(
                    $wallet,
                    $fromField,
                    $toField,
                    $releaseAmount
                );

                $remaining = $this->safeDecimal(math_sub($remaining, $releaseAmount));
            }
        }

        if ($this->safeCompare($remaining, 0, 8) > 0) {
            throw new \Exception('Order locked balance is not enough to release. Remaining: ' . $remaining);
        }
    }

    /**
     * 获取当前现货订单要使用的虚拟交易账户字段。
     *
     * 注意：现货交易只使用交易账户。
     * - 虚拟账户只认 balance_in_virtual_trade。
     * - 不再使用 balance_in_virtual_wallet。
     */
    private function getSpotVirtualSourceField(Wallet $wallet): ?string
    {
        if (!$this->walletColumnExists('balance_in_virtual_trade')) {
            return null;
        }

        $wallet->refresh();

        return \App\Services\Wallet\SpotFunds::field($wallet)==='balance_in_virtual_trade' ? 'balance_in_virtual_trade' : null;
    }

    /**
     * 在钱包两个字段之间安全移动资金。
     *
     * 使用 GREATEST 防止扣款字段出现负数。
     * 字段名只允许白名单，避免动态字段名造成 SQL 风险。
     */
    private function moveWalletAmount(Wallet $wallet, string $fromField, string $toField, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        $allowedFields = [
            'balance_in_wallet',
            'balance_in_trade',
            'balance_in_order',
            'balance_in_withdraw',
            'balance_in_lc',
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
        ];

        if (!in_array($fromField, $allowedFields, true) || !in_array($toField, $allowedFields, true)) {
            throw new \Exception('Invalid wallet balance field');
        }

        if (!$this->walletColumnExists($fromField) || !$this->walletColumnExists($toField)) {
            throw new \Exception('Wallet balance field does not exist');
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            throw new \Exception('Wallet not found');
        }

        $available = $this->safeDecimal($freshWallet->{$fromField} ?? 0);

        if ($this->safeCompare($available, $amount) < 0) {
            throw new \Exception('Insufficient balance on ' . $fromField . '. Available: ' . $available . ', Required: ' . $amount);
        }

        DB::statement(
            "UPDATE wallets SET {$fromField} = GREATEST(COALESCE({$fromField}, 0) - ?, 0), {$toField} = COALESCE({$toField}, 0) + ?, updated_at = ? WHERE id = ?",
            [$amount, $amount, Carbon::now(), $wallet->id]
        );

        $wallet->refresh();
    }

private function isFuturesUserVirtual(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (!$this->schemaHasTableCached('users') || !$this->schemaHasColumnCached('users', 'is_xn')) {
        return false;
    }

    if (array_key_exists($userId, self::$futuresUserVirtualCache)) {
        return self::$futuresUserVirtualCache[$userId];
    }

    self::$futuresUserVirtualCache[$userId] = DB::table('users')
        ->where('id', $userId)
        ->where('is_xn', true)
        ->exists();

    return self::$futuresUserVirtualCache[$userId];
}

private function getUserReferralSnapshot(int $userId): ?User
{
    if ($userId <= 0) {
        return null;
    }

    if (!array_key_exists($userId, self::$userReferralCache)) {
        self::$userReferralCache[$userId] = User::query()
            ->select(['id', 'referral_id', 'vip'])
            ->where('id', $userId)
            ->first();
    }

    return self::$userReferralCache[$userId];
}

private function convertAutoInvestAmountToQuoteCurrency($amount, int $fromCurrencyId, int $quoteCurrencyId): string
{
    $amount = $this->safeDecimal($amount);

    if ($this->safeCompare($amount, 0) <= 0 || $fromCurrencyId <= 0 || $quoteCurrencyId <= 0) {
        return '0';
    }

    if ($fromCurrencyId === $quoteCurrencyId) {
        return $amount;
    }

    $fromUsdtRate = $this->getCurrencyToUsdtRate($fromCurrencyId);
    $quoteUsdtRate = $this->getCurrencyToUsdtRate($quoteCurrencyId);

    if ($this->safeCompare($fromUsdtRate, 0) <= 0 || $this->safeCompare($quoteUsdtRate, 0) <= 0) {
        return '0';
    }

    $amountUsdt = math_multiply($amount, $fromUsdtRate);

    return $this->safeDecimal(
        math_divide($amountUsdt, $quoteUsdtRate)
    );
}

private function convertQuoteAmountToAutoInvestCurrency($quoteAmount, int $quoteCurrencyId, int $toCurrencyId): string
{
    $quoteAmount = $this->safeDecimal($quoteAmount);

    if ($this->safeCompare($quoteAmount, 0) <= 0 || $quoteCurrencyId <= 0 || $toCurrencyId <= 0) {
        return '0';
    }

    if ($quoteCurrencyId === $toCurrencyId) {
        return $quoteAmount;
    }

    $quoteUsdtRate = $this->getCurrencyToUsdtRate($quoteCurrencyId);
    $toUsdtRate = $this->getCurrencyToUsdtRate($toCurrencyId);

    if ($this->safeCompare($quoteUsdtRate, 0) <= 0 || $this->safeCompare($toUsdtRate, 0) <= 0) {
        return '0';
    }

    $quoteAsUsdt = math_multiply($quoteAmount, $quoteUsdtRate);

    return $this->safeDecimal(
        math_divide($quoteAsUsdt, $toUsdtRate)
    );
}

private function getCurrencyToUsdtRate(int $currencyId): string
{
    static $rateCache = [];

    if ($currencyId <= 0) {
        return '0';
    }

    if (isset($rateCache[$currencyId])) {
        return $rateCache[$currencyId];
    }

    if (!$this->schemaHasTableCached('currencies') || !$this->schemaHasTableCached('markets')) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    $currency = DB::table('currencies')
        ->select(['id', 'symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    $symbol = strtoupper(trim((string) $currency->symbol));

    if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
        $rateCache[$currencyId] = '1';
        return '1';
    }

    $usdt = DB::table('currencies')
        ->select(['id'])
        ->whereRaw('UPPER(symbol) = ?', ['USDT'])
        ->first();

    $usd = DB::table('currencies')
        ->select(['id'])
        ->whereRaw('UPPER(symbol) = ?', ['USD'])
        ->first();

    $quoteIds = [];

    if ($usdt) {
        $quoteIds[] = (int) $usdt->id;
    }

    if ($usd && !in_array((int) $usd->id, $quoteIds, true)) {
        $quoteIds[] = (int) $usd->id;
    }

    if (empty($quoteIds)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    /*
     * 正向交易对：
     * BTC / USDT
     * markets.last 就是 BTC 的 USDT 价格。
     */
    $directQuery = DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->where('base_currency_id', $currencyId)
        ->whereIn('quote_currency_id', $quoteIds)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $directQuery->orderByRaw(
            'CASE WHEN quote_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END'
        );
    }

    $directMarket = $directQuery->first();

    if ($directMarket && is_numeric($directMarket->last) && (float) $directMarket->last > 0) {
        $rateCache[$currencyId] = $this->safeDecimal($directMarket->last, 18);
        return $rateCache[$currencyId];
    }

    /*
     * 反向交易对：
     * USDT / BTC
     * 当前币种转 USDT = 1 / markets.last。
     */
    $reverseQuery = DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->whereIn('base_currency_id', $quoteIds)
        ->where('quote_currency_id', $currencyId)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $reverseQuery->orderByRaw(
            'CASE WHEN base_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END'
        );
    }

    $reverseMarket = $reverseQuery->first();

    if ($reverseMarket && is_numeric($reverseMarket->last) && (float) $reverseMarket->last > 0) {
        $rateCache[$currencyId] = $this->safeDecimal(
            math_divide(1, $reverseMarket->last),
            18
        );

        return $rateCache[$currencyId];
    }

    $currencyModel = \App\Models\Currency\Currency::find($currencyId);

    if ($currencyModel) {
        $fallbackRate = (new CurrencyRepository())->currencyPriceInUsd($currencyModel);

        if (is_numeric($fallbackRate) && (float) $fallbackRate > 0) {
            $rateCache[$currencyId] = $this->safeDecimal($fallbackRate, 18);

            return $rateCache[$currencyId];
        }
    }

    $rateCache[$currencyId] = '0';

    return '0';
}

    private function walletColumnExists(string $field): bool
    {
        return $this->schemaHasColumnCached('wallets', $field);
    }

    private function schemaHasTableCached(string $table): bool
    {
        if (!array_key_exists($table, self::$schemaTableCache)) {
            $cacheKey = 'schema:table:' . config('database.default') . ':' . $table;

            try {
                self::$schemaTableCache[$table] = (bool) Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->remember(
                    $cacheKey,
                    now()->addDay(),
                    function () use ($table) {
                        return Schema::hasTable($table);
                    }
                );
            } catch (\Throwable $e) {
                try {
                    self::$schemaTableCache[$table] = Schema::hasTable($table);
                } catch (\Throwable $e) {
                    self::$schemaTableCache[$table] = false;
                }
            }
        }

        return self::$schemaTableCache[$table];
    }

    private function schemaHasColumnCached(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, self::$schemaColumnCache)) {
            $cacheKey = 'schema:column:' . config('database.default') . ':' . $key;

            try {
                self::$schemaColumnCache[$key] = (bool) Cache::store(
                    (string) config('performance.cache_store', 'redis')
                )->remember(
                    $cacheKey,
                    now()->addDay(),
                    function () use ($table, $column) {
                        return Schema::hasColumn($table, $column);
                    }
                );
            } catch (\Throwable $e) {
                try {
                    self::$schemaColumnCache[$key] = Schema::hasColumn($table, $column);
                } catch (\Throwable $e) {
                    self::$schemaColumnCache[$key] = false;
                }
            }
        }

        return self::$schemaColumnCache[$key];
    }

    /**
     * 如果 orders 表有资金来源字段，则写入资金来源，方便后续报表或撮合逻辑识别。
     * 没有这些字段时会自动忽略，不影响现有表结构。
     */
    private function appendSpotOrderFundingColumns(array $insert, array $fundingMeta): array
    {
        $table = (new Order())->getTable();

        $optionalColumns = [
            'account_type' => $fundingMeta['account_type'] ?? null,
            'funding_account_type' => $fundingMeta['account_type'] ?? null,
            'wallet_account_type' => $fundingMeta['account_type'] ?? null,
            'balance_source_field' => $fundingMeta['source_field'] ?? null,
            'balance_order_field' => $fundingMeta['order_field'] ?? null,
            'virtual_balance_source' => $fundingMeta['source_field'] ?? null,
            'funding_amount' => $fundingMeta['amount'] ?? null,
        ];

        foreach ($optionalColumns as $column => $value) {
            try {
                if ($value !== null && $this->schemaHasColumnCached($table, $column)) {
                    $insert[$column] = $value;
                }
            } catch (\Throwable $e) {
                // Ignore optional columns for old schemas.
            }
        }

        return $insert;
    }


    /**
     * 下单前确保当前交易对两边的钱包都存在。
     */
    private function ensureWalletsForMarketBeforeOrder(): void
    {
        if (!$this->user || !$this->market) {
            return;
        }

        $currencyIds = array_filter([
            $this->market->base_currency_id ?? null,
            $this->market->quote_currency_id ?? null,
        ]);

        foreach ($currencyIds as $currencyId) {
            $this->getOrCreateWalletByCurrency($this->user->id, $currencyId, false);
        }
    }

    public function insert($insert)
    {
        Order::insert($insert);

        return $this->findById($this->uuid);
    }

    public function getMatchedOrder($type, $side, $market, $price, ?int $userId = null, ?string $domain = null)
    {
        $userId ??= auth()->id();
        if ($domain === null && $userId) {
            $model = Market::findOrFail($market);
            $domain = \App\Services\Order\SpotFunding::requestedDomain($userId, $side === 'buy' ? $model->quote_currency_id : $model->base_currency_id);
        }
        $domain ??= 'real';
        return Order::matchOpposite($type, $side, $price)
            ->whereMarketId($market)
            ->when($userId, fn($q) => $q->where('user_id', '<>', $userId))
            ->processable()
            ->where(function ($query) use ($domain) {
                $query->where('settlement_domain', $domain)->orWhere(function ($legacy) use ($domain) {
                    $legacy->whereNull('settlement_domain')->whereExists(function ($wallet) use ($domain) {
                        $wallet->selectRaw('1')->from('wallets')->whereColumn('wallets.user_id', 'orders.user_id')
                            ->whereRaw("wallets.currency_id = CASE WHEN orders.side = 'buy' THEN orders.quote_currency_id ELSE orders.base_currency_id END")
                            ->where($domain === 'real' ? 'balance_in_order' : 'balance_in_virtual_order', '>', 0)
                            ->where($domain === 'real' ? 'balance_in_virtual_order' : 'balance_in_order', '<=', 0);
                    });
                });
            })
            ->oldest()->orderBy('id')
            ->lockForUpdate()->first();
    }

    public function getFixedOrder($quantity, $price, $side)
    {
        $cursorOrder = new Order();
        $cursorOrder->quantity = $quantity;
        $cursorOrder->price = $price;
        $cursorOrder->fee_rate = 0;
        $cursorOrder->side = $side;
        $cursorOrder->type = Order::TYPE_LIMIT;

        return $cursorOrder;
    }

    public function lockOrder(Order $order)
    {
        $order->locked = true;
        $order->update();
    }

    public function unlockOrder(Order $order)
    {
        $order->locked = false;
        $order->update();
    }

    public function open($market = false)
    {
        $orders = Order::processable()->where('user_id', auth()->id());

        if ($market) {
            $marketId = $this->getMarketIdByNameCached($market);
            $orders->whereMarketId($marketId ?: 0);
        }

        $orders->has('market')->with('market.baseCurrency')->with('market.quoteCurrency');

        return $orders->get();
    }

    public function openFutures($market = false)
    {
        $userId = (int) auth()->id();
        $hasMarketFilter = (bool) $market;
        $marketId = $hasMarketFilter ? $this->getMarketIdByNameCached($market) : null;
        $variant = 'positions:' . ($hasMarketFilter ? (int) ($marketId ?: 0) : 'all');

        return app(ReadModelCacheService::class)->rememberOpenFutures(
            $userId,
            $variant,
            function () use ($userId, $hasMarketFilter, $marketId) {
                $orders = FuturesContract::query()
                    ->select($this->openFuturesColumns())
                    ->where('user_id', $userId);

                if ($hasMarketFilter) {
                    $orders->whereMarketId($marketId ?: 0);
                }

                $orders->whereIn('status', ['active', 'scheduled']);
                $this->withOpenFuturesMarket($orders);

                return $orders->get();
            }
        );
    }

    public function openFuturesOrders($market = false)
    {
        $userId = (int) auth()->id();
        $hasMarketFilter = (bool) $market;
        $marketId = $hasMarketFilter ? $this->getMarketIdByNameCached($market) : null;
        $variant = 'pending:' . ($hasMarketFilter ? (int) ($marketId ?: 0) : 'all');

        return app(ReadModelCacheService::class)->rememberOpenFutures(
            $userId,
            $variant,
            function () use ($userId, $hasMarketFilter, $marketId) {
                $orders = FuturesContract::query()
                    ->select($this->openFuturesColumns())
                    ->where('user_id', $userId);

                if ($hasMarketFilter) {
                    $orders->whereMarketId($marketId ?: 0);
                }

                $orders->where('status', 'pending')
                    ->where('type', FuturesContract::TYPE_LIMIT);

                $this->withOpenFuturesMarket($orders);

                return $orders->get();
            }
        );
    }

    private function openFuturesColumns(): array
    {
        return [
            'id',
            'user_id',
            'market_id',
            'base_currency_id',
            'quote_currency_id',
            'is_long',
            'created_at',
            'quantity',
            'balance',
            'released_amount',
            'price',
            'leverage',
            'liquidation_price',
            'take_profit_price',
            'stop_loss_price',
            'type',
            'status',
            'scheduled_status',
            'timeframe_seconds',
            'start_at',
            'total_funding_fee_paid',
            'last_funding_fee_at',
        ];
    }

    private function withOpenFuturesMarket($orders): void
    {
        $orders->has('market')->with([
            'market:id,name,base_currency_id,quote_currency_id,base_precision,quote_precision',
            'market.baseCurrency:id,symbol',
            'market.quoteCurrency:id,symbol',
        ]);
    }

    private function getMarketIdByNameCached($market): ?int
    {
        $id = Market::whereName(trim((string)$market))->value('id');
        return $id ? (int)$id : null;
    }

    public function setUuid()
    {
        $this->uuid = generate_uuid();
    }

    public function triggerStopLimitMatchedOrders($price, $marketId)
    {
        $orders = Order::stopLimit()
            ->where('market_id', $marketId)
            ->oldest()
            ->matchByStopLimitCondition($price)
            ->lockForUpdate();

        return $orders->cursor();
    }

    private function findMergeableFuturesPosition(int $userId, int $marketId, bool $isLong, $leverage)
    {
        return FuturesContract::where('user_id', $userId)
            ->where('market_id', $marketId)
            ->where('is_long', $isLong)
            ->where('leverage', $leverage)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
    }

    private function mergeFuturesPosition(FuturesContract $existing, array $incomingData)
    {
        // Preserve provenance through merges; legacy or mixed funding is held for review.
        $oldDomain = $existing->referral_balance_domain;
        $incomingDomain = $incomingData['referral_balance_domain'] ?? 'unknown';
        $existing->referral_balance_domain = $oldDomain && $oldDomain === $incomingDomain ? $oldDomain : 'unknown';
        $incomingQuantity = $incomingData['quantity'];
        $incomingPrice = $incomingData['price'];
        $incomingBalance = $incomingData['balance'];
        $incomingEntryFee = $incomingData['entry_fee'] ?? 0;
        $incomingTakeProfitPrice = $incomingData['take_profit_price'] ?? null;
        $incomingStopLossPrice = $incomingData['stop_loss_price'] ?? null;

        $oldQuantity = $existing->quantity;
        $oldPrice = $existing->price;

        $mergedQuantity = math_sum($oldQuantity, $incomingQuantity);

        if (math_compare($mergedQuantity, 0) <= 0) {
            throw new \Exception('Merged quantity must be greater than zero');
        }

        $oldCost = math_multiply($oldPrice, $oldQuantity);
        $newCost = math_multiply($incomingPrice, $incomingQuantity);
        $mergedPrice = math_divide(math_sum($oldCost, $newCost), $mergedQuantity);

        $precision = 8;

        if ($existing->relationLoaded('market') && $existing->market) {
            $precision = (int) $existing->market->quote_precision;
        } elseif ($this->market && isset($this->market->quote_precision)) {
            $precision = (int) $this->market->quote_precision;
        } else {
            $marketPrecision = Market::where('id', $existing->market_id)->value('quote_precision');

            if ($marketPrecision !== null) {
                $precision = (int) $marketPrecision;
            }
        }

        $existing->price = math_formatter($mergedPrice, $precision);
        $existing->quantity = $mergedQuantity;
        $existing->balance = math_sum($existing->balance, $incomingBalance);
        $existing->entry_fee = math_sum($existing->entry_fee ?? 0, $incomingEntryFee);
        $existing->trade_margin_amount = math_sum($existing->trade_margin_amount ?? 0, $incomingData['trade_margin_amount'] ?? 0);
        $existing->auto_invest_margin_amount = math_sum($existing->auto_invest_margin_amount ?? 0, $incomingData['auto_invest_margin_amount'] ?? 0);
        $existing->total_margin_amount = math_sum($existing->total_margin_amount ?? 0, $incomingData['total_margin_amount'] ?? 0);

        /**
         * 新爆仓价：使用合并后的真实有效保证金计算。
         * 包含理财质押保证金，避免提前爆仓。
         */
        $existing->liquidation_price = $this->calculateFuturesLiquidationPriceByMargin(
            $existing->price,
            $existing->quantity,
            $existing->balance,
            (bool) $existing->is_long,
            $precision,
            $existing
        );

        if (empty($existing->take_profit_price) && !empty($incomingTakeProfitPrice)) {
            $existing->take_profit_price = $incomingTakeProfitPrice;
        }

        if (empty($existing->stop_loss_price) && !empty($incomingStopLossPrice)) {
            $existing->stop_loss_price = $incomingStopLossPrice;
        }

        $existing->status = 'active';

        if (empty($existing->activated_at)) {
            $existing->activated_at = now();
        }

        $existing->save();

        return $existing;
    }

    public function processFuturesLimitOrder(FuturesContract $future)
    {
        if (!$future->relationLoaded('market')) {
            $future->load('market');
        }

        $quote = app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($future);
        $marketPrice = math_formatter($quote['price'], $future->market->quote_precision);

        $canExecute = false;

        if ($future->is_long) {
            $canExecute = $marketPrice <= $future->price;
        } else {
            $canExecute = $marketPrice >= $future->price;
        }

        if ($canExecute) {
            $this->executeFuturesLimitOrder($future, $marketPrice);
            return true;
        }

        return false;
    }

    private function executeFuturesLimitOrder(FuturesContract $future, $executionPrice)
    {
        return DB::transaction(function () use ($future, $executionPrice) {
            if (!$future->relationLoaded('market')) {
                $future->load('market');
            }

            $future = FuturesContract::where('id', $future->id)
                ->lockForUpdate()
                ->first();

            if (!$future || $future->status !== 'pending') {
                return false;
            }

            if (!$future->relationLoaded('market')) {
                $future->load('market');
            }

            $quote=app(\App\Services\Market\VerifiedDerivativePrice::class)->forContract($future);
            $executionPrice=$quote['price'];
            if (($future->is_long && math_compare($executionPrice,$future->price)>0) || (!$future->is_long && math_compare($executionPrice,$future->price)<0)) return false;
            $future->opening_price_source=$quote['source'];
            $netMargin = $future->balance;
            $positionValue = math_multiply($netMargin, $future->leverage);
            $amount = math_divide($positionValue, $executionPrice);

            $liquidationPrice = $this->calculateFuturesLiquidationPriceByMargin(
                $executionPrice,
                $amount,
                $future->balance,
                (bool) $future->is_long,
                (int) $future->market->quote_precision,
                $future
            );

            $wallet = $this->getOrCreateWalletByCurrency(
                $future->user_id,
                $future->quote_currency_id
            );

            if (!$wallet) {
                throw new \Exception('Wallet create failed');
            }

            /**
             * 新逻辑：限价单成交时，只扣掉真实放入 balance_in_order 的交易账户部分。
             * 理财质押部分已经在下单时写入 auto_invest_orders.used_margin。
             */
            $tradeLockedAmount = $this->safeDecimal($future->trade_margin_amount ?? 0);

            if ($this->isFuturesUserVirtual((int) $future->user_id) || $this->isFuturesVirtualPosition($future, $wallet)) {
                /*
                 * 虚拟账户限价单成交：
                 * 下单时已经从 balance_in_virtual_trade 转入 balance_in_virtual_order，
                 * 成交时从 balance_in_virtual_order 扣掉，平仓后统一回 balance_in_virtual_trade。
                 */
                if ($this->safeCompare($tradeLockedAmount, 0) > 0) {
                    $this->decreaseWalletField(
                        $wallet,
                        'balance_in_virtual_order',
                        $tradeLockedAmount,
                        true
                    );
                }
            } elseif ($this->safeCompare($tradeLockedAmount, 0) > 0) {
                $this->walletService->decrease($wallet, $tradeLockedAmount, 'order');
            }

            $existingPosition = $this->findMergeableFuturesPosition(
                $future->user_id,
                $future->market_id,
                (bool) $future->is_long,
                $future->leverage
            );

            if ($existingPosition && $existingPosition->id !== $future->id) {
                /**
                 * 如果 pending 单成交后需要并入已有 active 仓位，
                 * 需要把这笔 pending 单的理财锁定 source_id 转到已有仓位 id 上。
                 */
                $this->moveAutoInvestMarginLocks((string) $future->id, (string) $existingPosition->id);

                $merged = $this->mergeFuturesPosition($existingPosition, [
                    'price' => $executionPrice,
                    'quantity' => $amount,
                    'balance' => $future->balance,
                    'entry_fee' => $future->entry_fee ?? 0,
                    'take_profit_price' => $future->take_profit_price,
                    'stop_loss_price' => $future->stop_loss_price,
                    'trade_margin_amount' => $future->trade_margin_amount ?? 0,
                    'auto_invest_margin_amount' => $future->auto_invest_margin_amount ?? 0,
                    'total_margin_amount' => $future->total_margin_amount ?? 0,
                    'referral_balance_domain' => $future->referral_balance_domain ?? 'unknown',
                ]);

                $this->addFuturesReferralTransactions($future, $future->entry_fee ?? 0, 'entry', (string)$future->id);

                // 限价单成交后，手续费减免部分才返还
                $this->refundFuturesFeeToUser($merged, $future->entry_fee ?? 0, 'entry', $wallet);

                $future->delete();

                DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

                return $merged;
            }

            $future->price = $executionPrice;
            $future->quantity = $amount;
            $future->liquidation_price = $liquidationPrice;
            $future->status = 'active';
            $future->activated_at = now();
            $future->save();

            $this->addFuturesReferralTransactions($future, $future->entry_fee ?? 0, 'entry', (string)$future->id);

            // 限价单成交后，手续费减免部分才返还
            $this->refundFuturesFeeToUser($future, $future->entry_fee ?? 0, 'entry', $wallet);

            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));

            return $future;
        });
    }

    private function insertAutoInvestMarginLock(array $data): void
    {
        try {
            DB::table('auto_invest_margin_locks')->insert($data);
            return;
        } catch (\Illuminate\Database\QueryException $e) {
            if (!$this->shouldRetryAfterPostgresSequenceError($e, 'auto_invest_margin_locks')) {
                throw $e;
            }
        }

        $this->syncPostgresSequence('auto_invest_margin_locks');
        DB::table('auto_invest_margin_locks')->insert($data);
    }

    private function shouldRetryAfterPostgresSequenceError(\Illuminate\Database\QueryException $e, string $table): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23505' && strpos($e->getMessage(), $table) !== false;
    }

    private function syncPostgresSequence(string $table, string $column = 'id'): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [$table, $column]
        );

        $sequenceName = $sequence->sequence_name ?? null;

        if (!$sequenceName) {
            return;
        }

        DB::statement(
            'SELECT setval(' . $this->quotePgLiteral($sequenceName) . '::regclass, (SELECT COALESCE(MAX(' . $this->quotePgIdentifier($column) . '), 0) + 1 FROM ' . $this->quotePgIdentifier($table) . ')::bigint, false)'
        );
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function quotePgLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function moveAutoInvestMarginLocks(string $fromSourceId, string $toSourceId): void
    {
        if (!$this->schemaHasTableCached('auto_invest_margin_locks')) {
            return;
        }

        DB::table('auto_invest_margin_locks')
            ->where('source_type', 'futures')
            ->where('source_id', $fromSourceId)
            ->where('status', 'active')
            ->update([
                'source_id' => $toSourceId,
                'updated_at' => now(),
            ]);
    }

    private function dispatchCopyTradingOpen($contractId): void
    {
        if (request()->boolean('copy_trading')) {
            return;
        }

        try {
            app(CopyTradingService::class)->copyFuturesOpen((string) $contractId, request()->only([
                'market',
                'type',
                'side',
                'leverage',
                'quantity',
                'price',
                'quoteQuantity',
                'enable_tp_sl',
                'take_profit_price',
                'stop_loss_price',
            ]));
        } catch (\Throwable $e) {
            Log::warning('Copy trading open dispatch failed', [
                'source_contract_id' => (string) $contractId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchCopyTradingClose(string $contractId): void
    {
        if (request()->boolean('copy_trading')) {
            return;
        }

        try {
            app(CopyTradingService::class)->copyFuturesClose($contractId);
        } catch (\Throwable $e) {
            Log::warning('Copy trading close dispatch failed', [
                'source_contract_id' => $contractId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validateTPSLForMarketOrder(FuturesContract $future, $entryPrice, $isLong)
    {
        $takeProfitPrice = $future->take_profit_price;
        $stopLossPrice = $future->stop_loss_price;

        if ($takeProfitPrice && $takeProfitPrice > 0) {
            if ($isLong) {
                if (math_compare($takeProfitPrice, $entryPrice) <= 0) {
                    $future->take_profit_price = null;
                    Log::warning('Invalid TP price for market order - cleared', [
                        'future_id' => $future->id,
                        'tp_price' => $takeProfitPrice,
                        'entry_price' => $entryPrice
                    ]);
                }
            } else {
                if (math_compare($takeProfitPrice, $entryPrice) >= 0) {
                    $future->take_profit_price = null;
                    Log::warning('Invalid TP price for market order - cleared', [
                        'future_id' => $future->id,
                        'tp_price' => $takeProfitPrice,
                        'entry_price' => $entryPrice
                    ]);
                }
            }
        }

        if ($stopLossPrice && $stopLossPrice > 0) {
            if ($isLong) {
                if (math_compare($stopLossPrice, $entryPrice) >= 0) {
                    $future->stop_loss_price = null;
                    Log::warning('Invalid SL price for market order - cleared', [
                        'future_id' => $future->id,
                        'sl_price' => $stopLossPrice,
                        'entry_price' => $entryPrice
                    ]);
                }
            } else {
                if (math_compare($stopLossPrice, $entryPrice) <= 0) {
                    $future->stop_loss_price = null;
                    Log::warning('Invalid SL price for market order - cleared', [
                        'future_id' => $future->id,
                        'sl_price' => $stopLossPrice,
                        'entry_price' => $entryPrice
                    ]);
                }
            }
        }

        if (
            $takeProfitPrice && $stopLossPrice &&
            $takeProfitPrice > 0 && $stopLossPrice > 0
        ) {
            if ($isLong) {
                if (math_compare($takeProfitPrice, $stopLossPrice) <= 0) {
                    $future->take_profit_price = null;
                    $future->stop_loss_price = null;
                    Log::warning('TP/SL conflict for market order - cleared both', [
                        'future_id' => $future->id,
                        'tp_price' => $takeProfitPrice,
                        'sl_price' => $stopLossPrice
                    ]);
                }
            } else {
                if (math_compare($stopLossPrice, $takeProfitPrice) <= 0) {
                    $future->take_profit_price = null;
                    $future->stop_loss_price = null;
                    Log::warning('TP/SL conflict for market order - cleared both', [
                        'future_id' => $future->id,
                        'tp_price' => $takeProfitPrice,
                        'sl_price' => $stopLossPrice
                    ]);
                }
            }
        }
    }

    public function storeSwap()
    {
    }
}
