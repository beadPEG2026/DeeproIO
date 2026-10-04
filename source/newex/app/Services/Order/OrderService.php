<?php

namespace App\Services\Order;

use App\Events\OrderBookUpdated;
use App\Events\WalletUpdated;
use App\Jobs\Market\MarketCapCalculationJob;
use App\Jobs\Order\CreateOrderJob;
use App\Models\Order\Order;
use App\Models\Wallet\Wallet;
use App\Repositories\Order\OrderRepository;
use App\Services\Transaction\TransactionService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Setting;

class OrderService {

    // fields properties
    protected $process_id, $market, $order, $cursorOrder, $cursorQuantity, $cursorFill, $cursorRemaining, $totalRemaining, $quickFill, $fee, $cursorFee, $stopMatching, $isBuyMarket, $initialQuantity;

    // service properties
    public $transactionService;
    public $walletService;

    // field arrays
    public $transactions = [];

    // Pending events for batch dispatch
    protected $pendingOrderBookEvents = [];
    protected $pendingWalletEvents = [];

    private $orderRepository;

    public function __construct(
        ?OrderRepository $orderRepository = null,
        ?WalletService $walletService = null
    ) {
        $this->orderRepository = $orderRepository ?? new OrderRepository();
        $this->walletService = $walletService ?? new WalletService();
    }

    /**
     * Process match orders.
     * Optimized: Uses iterative loop instead of recursion, reduced refresh calls,
     * batched event dispatching, and async job dispatch.
     *
     * @param $order
     * @return bool
     */
    public function processOrder(Order $order, $firstLoop = false, $withoutAdjust = false, $isSwap = false) {
        return DB::transaction(function() use($order,$firstLoop,$withoutAdjust,$isSwap) {
            DB::statement('SELECT pg_advisory_xact_lock(8192026, ?)',[(int)$order->market_id]);
            $fresh=Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$fresh) return true; // A queued retry may refer to an already filled/cancelled order.
            $ok=$this->processMatchedOrder($fresh,$firstLoop,$withoutAdjust,$isSwap);
            if (!$ok) throw new \RuntimeException('Matching failed; balances and referral events rolled back');
            return true;
        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    private function isStockOrder(): bool { return \App\Services\Market\StockAssets::supports($this->order->market->name); }

    private function processMatchedOrder(Order $order, $firstLoop = false, $withoutAdjust = false, $isSwap = false) {

        // Reset pending events for this order processing session
        $this->pendingOrderBookEvents = [];
        $this->pendingWalletEvents = [];
        
        // order that needs to be executed
        $this->order = $order;

        // If order is buy market
        $this->isBuyMarket = order_is_buy_market($this->order->type, $this->order->side);

        // Triggered Field
        $triggeredField = $this->isBuyMarket ? 'quote_quantity' : 'quantity';

        // Initial amount
        $this->initialQuantity = $this->order->{$triggeredField};

        // define initial order quantity
        $this->cursorRemaining = $this->totalRemaining = $this->initialQuantity;

        // assign transactions service (reuse if already created)
        if (!$this->transactionService) {
            $this->transactionService = new TransactionService();
        }

        // generate process id
        $this->process_id = Str::uuid();
        
        $this->stopMatching = false;
        $isFirstIteration = $firstLoop;
        $marketCapCalculated = false;

        // Iterative loop instead of recursion for better performance
        while (!$this->stopMatching) {
            try {
                // Find order with best rate
                $this->prepareOrderCursor($isSwap);

                // If order not found stop processing
                if(!$this->cursorOrder) {
                    if(!order_is_limit($this->order->type)) {
                        $this->revertPending();
                    } elseif($isFirstIteration && !$withoutAdjust) {
                        $this->adjustFee();
                    }

                    $this->stopMatching = true;
                    break;
                }

                // Calculate Market Cap only once per order (async dispatch)
                if (!$marketCapCalculated) {
                    MarketCapCalculationJob::dispatch($this->order->market)->onQueue('{okrcoin}:low');
                    $marketCapCalculated = true;
                }

                // Get current matched order quantity
                $this->cursorQuantity = $this->cursorOrder->quantity;

                // Check if the order will be filled immediately or partially
                if($this->isBuyMarket) {
                    $this->quickFill = $this->cursorQuantity >= math_divide($this->cursorRemaining, $this->cursorOrder->price);
                } else {
                    $this->quickFill = $this->cursorQuantity >= $this->cursorRemaining;
                }

                // Filled amount in quote currency
                if($this->isBuyMarket) {
                    $cursorFill = $this->quickFill ? math_divide($this->cursorRemaining, $this->cursorOrder->price) : $this->cursorOrder->quantity;
                } else {
                    $cursorFill = $this->quickFill ? $this->cursorRemaining : $this->cursorOrder->quantity;
                }

                $isOrderPriceGreater = $this->order->price > $this->cursorOrder->price;

                // Spent amount in base currency
                if($this->isBuyMarket) {
                    $convertedFill = $this->quickFill ? $this->totalRemaining : math_multiply($this->cursorOrder->quantity, $this->cursorOrder->price);
                } else {
                    $convertedFill = $this->quickFill ? $this->totalRemaining : $this->cursorOrder->quantity;
                }


                // Decrease filled amount
                $this->cursorRemaining = math_sub($this->cursorRemaining, $convertedFill);
                $this->totalRemaining = math_sub($this->totalRemaining, $convertedFill);

                // Decrease quantity of order (database update)
                $this->order->decrementField($triggeredField, $convertedFill);
                
                // Update local model value to avoid refresh
                $this->order->{$triggeredField} = math_sub($this->order->{$triggeredField}, $convertedFill);

                // Decrease fee
                if($this->isBuyMarket) {
                    $fee = math_percentage($convertedFill, $this->order->fee_rate);
                    $this->order->decrementField('fee', $fee);
                    $this->order->fee = math_sub($this->order->fee, $fee);
                }

                if (!$this->isBuyMarket && order_is_buy($this->order->side)) {
                    $reservedFee=math_percentage(math_multiply($cursorFill,$this->order->price),$this->order->fee_rate);
                    $this->order->decrementField('fee',$reservedFee);
                    $this->order->fee=math_sub($this->order->fee,$reservedFee);
                }

                // Decrease filled quantity of matched order
                if($this->cursorOrder->id) {
                    $this->cursorOrder->decrementField('quantity', $cursorFill);
                    $this->cursorOrder->quantity = math_sub($this->cursorOrder->quantity, $cursorFill);
                } else {
                    $this->cursorOrder->quantity = math_sub($this->cursorOrder->quantity, $cursorFill);
                }

                // Queue orderbook events for batch dispatch
                $this->pendingOrderBookEvents[] = [
                    'order' => $this->order->toArray(),
                    'name' => $this->order->market->name,
                    'decimals' => $this->order->market->quote_precision,
                    'type' => 'update',
                    'quantity' => $convertedFill
                ];

                if($this->cursorOrder->id) {
                    $this->pendingOrderBookEvents[] = [
                        'order' => $this->cursorOrder->toArray(),
                        'name' => $this->cursorOrder->market->name,
                        'decimals' => $this->cursorOrder->market->quote_precision,
                        'type' => 'update',
                        'quantity' => $cursorFill
                    ];
                }

                // Process order transaction.
                // Before the original transaction service updates balances, capture wallets that are using
                // virtual funds. After the original settlement, we move any real-account deltas back into
                // virtual fields. This keeps the existing table structure and does not require new columns.
                $virtualSettlementSnapshots = $this->captureVirtualSettlementSnapshots();

                $this->transactionService->process([
                    'process_id' => $this->process_id,
                    'order' => $this->order,
                    'matched_order' => $this->cursorOrder,
                    'filled_quantity' => $convertedFill,
                    'cursor_quantity' => $cursorFill,
                    'triggeredField' => $triggeredField,
                    'initialQuantity' => $this->initialQuantity,
                    'is_order_price_greater' => $isOrderPriceGreater,
                    'cursor_remaining' => $this->cursorRemaining
                ]);

                $this->applyVirtualSettlementCorrections($virtualSettlementSnapshots);

                // Check if we should continue matching
                if (!$this->processMatching()) {
                    if ($this->isBuyMarket) {
                        $this->revertPendingFee();
                    }
                    $this->stopMatching = true;
                }

                $isFirstIteration = false;

            } catch (\Throwable $e) {
                Log::error($e);
                // The outer transaction rolls back reservations and journals together.
                // Do not issue queries or publish balance events from an aborted SQL transaction.
                return false;
            }
        }

        // Dispatch all pending events at once
        $this->dispatchPendingEvents();
        $this->dispatchOrderbookCacheUpdate($this->order->market->name);
        
        return true;
    }
    
    /**
     * Dispatch all pending orderbook events in batch
     */
    protected function dispatchPendingEvents() {
        foreach ($this->pendingOrderBookEvents as $eventData) {
            event(new OrderBookUpdated([
                'order' => $eventData['order'],
                'name' => $eventData['name'],
                'decimals' => $eventData['decimals']
            ], $eventData['type'], $eventData['quantity']));
        }
        $this->pendingOrderBookEvents = [];
    }

    /**
     * Get the next found order by criteria.
     *
     * @return Order $cursorOrder
     */
    private function prepareOrderCursor($swapFixed = false) {

        // Recheck market identity and switches for queued and resting orders.
        if (app(\App\Services\Market\HongKongPriceProduct::class)->tradingReason($this->order->market, false)) {
            $this->cursorOrder = null;
            return;
        }

        // Quotes alone never authorize settlement. A funded maker order is required.
        $this->cursorOrder = $this->orderRepository->getMatchedOrder(
            $this->order->type, $this->order->side, $this->order->market_id,
            $this->order->price, (int)$this->order->user_id, SpotFunding::domain($this->order)
        );
        $service=app(\App\Services\Market\FundedLiquidity::class);
        $quoted=$service->cursor($this->order);
        if ($quoted && (!$this->cursorOrder || ($this->order->side === "buy"
            ? bccomp($quoted->price,$this->cursorOrder->price,18)<0
            : bccomp($quoted->price,$this->cursorOrder->price,18)>0))) {
            $needed=$this->isBuyMarket ? bcdiv((string)$this->cursorRemaining,(string)$quoted->price,18) : (string)$this->cursorRemaining;
            $funded=$service->reserve($this->order,$quoted,$needed);
            if($funded)$this->cursorOrder=$funded;
        }
    }

    /**
     * Check if order process is executable
     *
     * @return boolean
     */
    private function processMatching() {
        return $this->cursorRemaining > 0;
    }

    /**
     * Revert pending order balance to the wallet balance
     */
    private function revertPending() {

        $fee = 0;

        $revertedWallet = order_is_buy($this->order->side) ? $this->order->walletQuote : $this->order->walletBase;

        if(order_is_buy($this->order->side)) {
            $fee = $this->order->fee;
        }

        $this->revertSpotPendingBalance($revertedWallet, $this->cursorRemaining, $fee);
        $this->order->delete();

        // Wallet Updated
        DB::afterCommit(fn () => event(new WalletUpdated($revertedWallet)));
    }

    /**
     * Revert pending fee for buy market order
     */
    private function revertPendingFee() {

        $wallet = $this->order->walletQuote;
        $fee = $this->order->fee;
        $this->revertSpotPendingBalance($wallet, $fee, 0);

        // Wallet Updated
        DB::afterCommit(fn () => event(new WalletUpdated($wallet)));
    }

    /**
     * @param $market_id
     */
    public function processStopLimitOrders($market_id) {

        // Get last price
        $last_price = market_get_stats($market_id, 'last');

        // Get matched stop limit orders
        $orders = $this->orderRepository->triggerStopLimitMatchedOrders($last_price, $market_id);

        foreach ($orders as $order) {
            DB::transaction(function()use($order,$market_id){
                if (!order_limit_should_be_processed($order,$market_id,$order->trigger_price,$order->trigger_condition)) return;
                CreateOrderJob::dispatch($order)->onQueue('{okrcoin}:orders');
                event(new OrderBookUpdated(['order'=>$order->toArray(),'name'=>$order->market->name,'decimals'=>$order->market->quote_precision],'store'));
            },DB_REPEAT_AFTER_DEADLOCK);
        }
    }

    /*
     * Set maker fee instead of taker fee if user shares liquidity
     */
    public function adjustFee() {

        $fee_rate = Setting::get('trade.maker_fee', INITIAL_TRADE_MAKER_FEE);
        $this->order->fee_rate = $fee_rate;
        $this->order->update();

        if(!order_is_buy($this->order->side)) {
            return true;
        }

        $wallet = $this->order->walletQuote;

        $orderMakerFee = math_percentage(math_multiply($this->order->quantity, $this->order->price), $fee_rate);

        $feeDifference = math_sub($this->order->fee, $orderMakerFee);

        $this->order->fee = $orderMakerFee;
        $this->order->update();

        // Return maker fee difference to the same account type that locked the order.
        if ($this->shouldUseVirtualSpotWallet($wallet)) {
            $this->increaseWalletField($wallet, 'balance_in_virtual_trade', $feeDifference);
            $this->decreaseWalletField($wallet, 'balance_in_virtual_order', $feeDifference, false);
        } else {
            // Increase wallet balance
            $this->walletService->increase($wallet, $feeDifference, 'trade');

            // Increase wallet pending balance
            $this->walletService->decrease($wallet, $feeDifference, 'order');
        }

        // Wallet Updated
        DB::afterCommit(fn()=>event(new WalletUpdated($wallet)));
    }

    /**
     * Capture wallets that should settle through virtual balances.
     *
     * No extra order columns are required. If the funding wallet has balance_in_virtual_order > 0,
     * this order is considered virtual. Any real-account changes made by the legacy transaction
     * service are corrected immediately after the service finishes.
     */
    private function captureVirtualSettlementSnapshots(): array
    {
        $snapshots = [];

        $this->addVirtualSettlementSnapshotsForOrder($snapshots, $this->order);

        if ($this->cursorOrder && !empty($this->cursorOrder->id)) {
            $this->addVirtualSettlementSnapshotsForOrder($snapshots, $this->cursorOrder);
        }

        return $snapshots;
    }

    private function addVirtualSettlementSnapshotsForOrder(array &$snapshots, $order): void
    {
        if (!$order || empty($order->id)) {
            return;
        }

        try {
            if (method_exists($order, 'loadMissing')) {
                $order->loadMissing(['walletQuote', 'walletBase']);
            }
        } catch (\Throwable $e) {
            // Some synthetic liquidity orders do not have relations. We can still continue with fallbacks.
        }

        $isBuy = order_is_buy($order->side);

        $fundingWallet = $isBuy ? ($order->walletQuote ?? null) : ($order->walletBase ?? null);
        $receivingWallet = $isBuy ? ($order->walletBase ?? null) : ($order->walletQuote ?? null);

        if (!$fundingWallet) {
            $fundingCurrencyId = $isBuy ? ($order->quote_currency_id ?? null) : ($order->base_currency_id ?? null);
            $fundingWallet = $this->findWalletForOrder($order, $fundingCurrencyId);
        }

        if (!$receivingWallet) {
            $receivingCurrencyId = $isBuy ? ($order->base_currency_id ?? null) : ($order->quote_currency_id ?? null);
            $receivingWallet = $this->findWalletForOrder($order, $receivingCurrencyId);
        }

        if (!$fundingWallet || !$receivingWallet) {
            return;
        }

        try {
            $fundingWallet->refresh();
            $receivingWallet->refresh();
        } catch (\Throwable $e) {
            Log::error($e);
            return;
        }

        if (SpotFunding::domain($order) !== "virtual") {
            return;
        }

        /*
         * 只要本订单的资金锁定在虚拟挂单中，这次成交就属于虚拟账户成交。
         * 因此不只是扣款钱包要修正，成交后收到的方向代币钱包也必须加入快照。
         */
        $this->addWalletSnapshot($snapshots, $fundingWallet, true);
        $this->addWalletSnapshot($snapshots, $receivingWallet, true);
    }

    private function findWalletForOrder($order, $currencyId): ?Wallet
    {
        if (!$order || !$currencyId || empty($order->user_id)) {
            return null;
        }

        return Wallet::query()
            ->where('user_id', $order->user_id)
            ->where('currency_id', $currencyId)
            ->first();
    }

    private function addWalletSnapshot(array &$snapshots, Wallet $wallet, bool $virtualSettlement = false): void
    {
        $snapshots[$wallet->id] = [
            'wallet_id' => $wallet->id,
            'virtual_settlement' => $virtualSettlement || (($snapshots[$wallet->id]['virtual_settlement'] ?? false) === true),
            'balance_in_wallet' => $this->safeDecimal($wallet->balance_in_wallet ?? 0),
            'balance_in_trade' => $this->safeDecimal($wallet->balance_in_trade ?? 0),
            'balance_in_order' => $this->safeDecimal($wallet->balance_in_order ?? 0),
            'balance_in_virtual_trade' => $this->safeDecimal($wallet->balance_in_virtual_trade ?? 0),
            'balance_in_virtual_order' => $this->safeDecimal($wallet->balance_in_virtual_order ?? 0),
            'balance_in_virtual_wallet' => $this->safeDecimal($wallet->balance_in_virtual_wallet ?? 0),
        ];
    }

    private function applyVirtualSettlementCorrections(array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $wallet = Wallet::query()
                ->where('id', $snapshot['wallet_id'])
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                continue;
            }

            /**
             * Legacy settlement may decrease balance_in_order even when the order was locked in
             * balance_in_virtual_order. Restore real locked balance to its previous value and consume
             * virtual locked balance instead.
             */
            $beforeRealOrder = $this->safeDecimal($snapshot['balance_in_order'] ?? 0);
            $afterRealOrder = $this->safeDecimal($wallet->balance_in_order ?? 0);

            if ($this->safeCompare($beforeRealOrder, $afterRealOrder) > 0) {
                $consumedFromRealOrder = $this->safeDecimal(math_sub($beforeRealOrder, $afterRealOrder));
                $virtualOrderBalance = $this->safeDecimal($wallet->balance_in_virtual_order ?? 0);
                $virtualConsume = $this->safeCompare($virtualOrderBalance, $consumedFromRealOrder) >= 0
                    ? $consumedFromRealOrder
                    : $virtualOrderBalance;

                DB::statement(
                    "UPDATE wallets
                     SET balance_in_order = ?,
                         balance_in_virtual_order = GREATEST(COALESCE(balance_in_virtual_order, 0) - ?, 0),
                         updated_at = ?
                     WHERE id = ?",
                    [$beforeRealOrder, $virtualConsume, now(), $wallet->id]
                );

                $wallet->refresh();
            }

            /**
             * Legacy settlement may credit received funds into a real account field.
             * For virtual settlements, move every new real-account credit into balance_in_virtual_trade.
             * This covers both common paths:
             * - balance_in_trade: normal exchange trading balance
             * - balance_in_wallet: some older settlement implementations credit funding/account balance
             */
            if (($snapshot['virtual_settlement'] ?? false) === true) {
                $beforeRealTrade = $this->safeDecimal($snapshot['balance_in_trade'] ?? 0);
                $afterRealTrade = $this->safeDecimal($wallet->balance_in_trade ?? 0);

                if ($this->safeCompare($afterRealTrade, $beforeRealTrade) > 0) {
                    $creditedToRealTrade = $this->safeDecimal(math_sub($afterRealTrade, $beforeRealTrade));

                    DB::statement(
                        "UPDATE wallets
                         SET balance_in_trade = GREATEST(COALESCE(balance_in_trade, 0) - ?, 0),
                             balance_in_virtual_trade = COALESCE(balance_in_virtual_trade, 0) + ?,
                             updated_at = ?
                         WHERE id = ?",
                        [$creditedToRealTrade, $creditedToRealTrade, now(), $wallet->id]
                    );

                    $wallet->refresh();
                }

                if ($this->walletColumnExists('balance_in_wallet')) {
                    $beforeRealWallet = $this->safeDecimal($snapshot['balance_in_wallet'] ?? 0);
                    $afterRealWallet = $this->safeDecimal($wallet->balance_in_wallet ?? 0);

                    if ($this->safeCompare($afterRealWallet, $beforeRealWallet) > 0) {
                        $creditedToRealWallet = $this->safeDecimal(math_sub($afterRealWallet, $beforeRealWallet));

                        DB::statement(
                            "UPDATE wallets
                             SET balance_in_wallet = GREATEST(COALESCE(balance_in_wallet, 0) - ?, 0),
                                 balance_in_virtual_trade = COALESCE(balance_in_virtual_trade, 0) + ?,
                                 updated_at = ?
                             WHERE id = ?",
                            [$creditedToRealWallet, $creditedToRealWallet, now(), $wallet->id]
                        );

                        $wallet->refresh();
                    }
                }
            }

            /**
             * Final safety net: real trade/order balances should never be negative.
             */
            DB::statement(
                "UPDATE wallets
                 SET balance_in_trade = GREATEST(COALESCE(balance_in_trade, 0), 0),
                     balance_in_order = GREATEST(COALESCE(balance_in_order, 0), 0),
                     balance_in_virtual_trade = GREATEST(COALESCE(balance_in_virtual_trade, 0), 0),
                     balance_in_virtual_order = GREATEST(COALESCE(balance_in_virtual_order, 0), 0),
                     balance_in_virtual_wallet = GREATEST(COALESCE(balance_in_virtual_wallet, 0), 0),
                     updated_at = ?
                 WHERE id = ?",
                [now(), $wallet->id]
            );

            $wallet->refresh();
            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));
        }
    }

    private function revertSpotPendingBalance(Wallet $wallet, $amount, $fee = 0): void
    {
        $amount = $this->safeDecimal($amount);
        $fee = $this->safeDecimal($fee);
        $totalReturn = $this->safeDecimal(math_sum($amount, $fee));

        if ($this->safeCompare($totalReturn, 0) <= 0) {
            return;
        }

        $wallet->refresh();

        if ($this->shouldUseVirtualSpotWallet($wallet)) {
            $this->decreaseWalletField($wallet, 'balance_in_virtual_order', $totalReturn, false);
            $this->increaseWalletField($wallet, 'balance_in_virtual_trade', $totalReturn);
            return;
        }

        $this->walletService->revert($wallet, $totalReturn, 'order', 0);
    }

    private function shouldUseVirtualSpotWallet(Wallet $wallet): bool
    {
        if ($this->order && in_array($this->order->settlement_domain, ['real','virtual'], true)) return $this->order->settlement_domain === 'virtual';
        return $this->walletHasVirtualLockedBalance($wallet)
            || ($this->walletColumnExists('balance_in_virtual_trade') && $this->safeCompare($wallet->balance_in_virtual_trade ?? 0, 0) > 0);
    }

    private function walletHasVirtualLockedBalance(Wallet $wallet): bool
    {
        if (!$this->walletColumnExists('balance_in_virtual_order')) {
            return false;
        }

        return $this->safeCompare($wallet->balance_in_virtual_order ?? 0, 0) > 0;
    }

    private function increaseWalletField(Wallet $wallet, string $field, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0 || !$this->walletColumnExists($field)) {
            return;
        }

        DB::statement(
            "UPDATE wallets
             SET {$field} = COALESCE({$field}, 0) + ?,
                 updated_at = ?
             WHERE id = ?",
            [$amount, now(), $wallet->id]
        );

        $wallet->refresh();
    }

    private function decreaseWalletField(Wallet $wallet, string $field, $amount, bool $strict = true): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0 || !$this->walletColumnExists($field)) {
            return;
        }

        $wallet->refresh();
        $available = $this->safeDecimal($wallet->{$field} ?? 0);

        if ($strict && $this->safeCompare($available, $amount) < 0) {
            throw new \Exception('Insufficient balance');
        }

        $decreaseAmount = $this->safeCompare($available, $amount) >= 0 ? $amount : $available;

        DB::statement(
            "UPDATE wallets
             SET {$field} = GREATEST(COALESCE({$field}, 0) - ?, 0),
                 updated_at = ?
             WHERE id = ?",
            [$decreaseAmount, now(), $wallet->id]
        );

        $wallet->refresh();
    }

    private function walletColumnExists(string $field): bool
    {
        try {
            return Schema::hasColumn('wallets', $field);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function safeDecimal($value, $scale = 18)
    {
        return \App\Support\Decimal::normalize($value, (int)$scale);
    }

    private function safeCompare($left, $right, $scale = 18)
    {
        return bccomp($this->safeDecimal($left, $scale), $this->safeDecimal($right, $scale), $scale);
    }

    public function dispatchOrderbookCacheUpdate($market) {
        DB::afterCommit(function () use ($market) {
            Cache::put("bidsModelCache.$market.updated", true);
            Cache::put("asksModelCache.$market.updated", true);
        });
    }
}
