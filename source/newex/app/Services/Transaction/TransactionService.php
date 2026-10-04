<?php

namespace App\Services\Transaction;

use App\Events\MarketStatsUpdated;
use App\Events\MarketTradePrivateUpdated;
use App\Events\MarketTradeUpdated;
use App\Events\WalletUpdated;
use App\Jobs\Order\ProcessStopLimitOrdersJob;
use App\Models\Order\Order;
use App\Models\User\User;
use App\Models\Transaction\Transaction;
use App\Repositories\Transaction\ReferralTransactionRepository;
use App\Services\Market\MarketService;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Setting;

class TransactionService {

    protected $order, $cursorOrder, $cursorRemaining, $process_id, $initialQuantity, $filledQuantity, $cursorQuantity, $triggeredField, $isBuyMarket, $isOrderPriceGreater;

    protected $fee = 0;
    protected $cursorFee = 0;

    public $walletService;

    // Cache for market service to avoid repeated instantiation
    protected $marketService;

    // Batch wallet updates to reduce event overhead
    protected $pendingWalletUpdates = [];

    public function __construct(?WalletService $walletService = null)
    {
        $this->walletService = $walletService ?? new WalletService();
        $this->marketService = new MarketService();
    }

    /**
     * Insert order transactions.
     *
     * @param $transaction
     * @return void
     */
    public function process($transaction)
    {
        $this->process_id = $transaction['process_id'];
        $this->order = $transaction['order'];
        $this->cursorOrder = $transaction['matched_order'];

        // Platform counterparties have their own non-login treasury identity and a reserved receipt.
        $platformCredit = $this->cursorOrder->platform_pool_id !== null;
        if (!$this->order->user_id || !$this->cursorOrder->id || (!$platformCredit && !$this->cursorOrder->user_id) || $this->cursorOrder->user_id == $this->order->user_id) {
            throw new \RuntimeException('SPOT_FUNDED_COUNTERPARTY_REQUIRED');
        }
        $domain = \App\Services\Order\SpotFunding::domain($this->order);
        if (!in_array($domain, ['real', 'virtual'], true) || \App\Services\Order\SpotFunding::domain($this->cursorOrder) !== $domain) {
            throw new \RuntimeException('SPOT_FUNDING_DOMAIN_MISMATCH');
        }

        $this->filledQuantity = $transaction['filled_quantity'];
        $this->cursorQuantity = $transaction['cursor_quantity'];
        $this->triggeredField = $transaction['triggeredField'];
        $this->initialQuantity = $transaction['initialQuantity'];
        $this->isOrderPriceGreater = $transaction['is_order_price_greater'];
        $this->cursorRemaining = $transaction['cursor_remaining'];
        $this->isBuyMarket = order_is_buy_market($this->order->type, $this->order->side);

        $orderReferralDomain = $this->referralFundingDomain($this->order);
        $cursorReferralDomain = !$platformCredit && $this->cursorOrder->id ? $this->referralFundingDomain($this->cursorOrder) : null;
        $filledConvertedQuantity = math_multiply($this->filledQuantity, $this->cursorOrder->price);

        $walletQuote = $this->cursorOrder->id ? $this->cursorOrder->walletQuote : null;
        $walletBase = $this->cursorOrder->id ? $this->cursorOrder->walletBase : null;

        // 当前订单用户 VIP 折扣，只用于计算返还，不再直接减少手续费扣除
        $orderVip = intval($this->order->user->vip ?? 0);
        $orderDiscount = $this->getVipFeeDiscount($orderVip);

        // 对手订单用户 VIP 折扣，只用于计算返还，不再直接减少手续费扣除
        $cursorVip = 0;
        if ($this->cursorOrder->id && $this->cursorOrder->user) {
            $cursorVip = intval($this->cursorOrder->user->vip ?? 0);
        }
        $cursorDiscount = $this->getVipFeeDiscount($cursorVip);

        // 原始手续费比例，现货手续费先按原始比例完整扣除
        $orderfee_rate = $this->order->fee_rate;

        $cursorFeeRate = $this->cursorOrder->fee_rate ?? 0;
        $cursorOrderfee_rate = $cursorFeeRate;

        if ($platformCredit) {
            $baseQuantity=$this->isBuyMarket?$this->cursorQuantity:$this->filledQuantity;
            $quoteQuantity=$this->isBuyMarket?$this->filledQuantity:$filledConvertedQuantity;
            $this->fee=math_percentage($quoteQuantity,$orderfee_rate);
            $this->cursorFee='0';
            app(\App\Services\Market\PlatformCredit::class)->settle($this->order,$this->cursorOrder,(string)$baseQuantity,(string)$quoteQuantity,(string)$this->fee);
        } elseif (order_is_buy($this->order->side)) {

            $quoteQuantity = $this->isBuyMarket ? $this->filledQuantity : $filledConvertedQuantity;
            $baseQuantity = $this->isBuyMarket ? $this->cursorQuantity : $this->filledQuantity;

            // Take original fee from two matched orders
            $this->fee = math_percentage($quoteQuantity, $orderfee_rate);

            if ($this->cursorOrder->id) {
                $this->cursorFee = math_percentage($quoteQuantity, $cursorOrderfee_rate);
            }

            // Reflect order user wallet
            $this->reflectBalances(
                $this->order->walletQuote,
                math_sum($quoteQuantity, $this->fee),
                $walletQuote,
                math_sub($quoteQuantity, $this->cursorFee)
            );

            // Reflect matched order user wallet
            $this->reflectBalances(
                $walletBase,
                $baseQuantity,
                $this->order->walletBase,
                $baseQuantity
            );

        } else {

            // Take original fee from two matched orders
            $this->fee = math_percentage($filledConvertedQuantity, $orderfee_rate);
            $this->cursorFee = math_percentage($filledConvertedQuantity, $cursorOrderfee_rate);

            // Reflect order user wallet
            $this->reflectBalances(
                $walletQuote,
                math_sum($filledConvertedQuantity, $this->cursorFee),
                $this->order->walletQuote,
                math_sub($filledConvertedQuantity, $this->fee)
            );

            // Reflect matched order user wallet
            $this->reflectBalances(
                $this->order->walletBase,
                $this->filledQuantity,
                $walletBase,
                $this->filledQuantity
            );
        }

        // Fee referral totals are calculated from the same event ledger for both sides.
        $referralFee = $referralCursorFee = '0';

        // Order Transaction
        $transactions = [
            'is_maker' => false,
            'process_id' => $this->process_id,
            'order_id' => $this->order->id,
            'user_id' => $this->order->user_id,
            'market_id' => $this->order->market->id,
            'order_type' => $this->order->type,
            'order_side' => $this->order->side,
            'fee' => $this->fee,
            'referral_fee' => $referralFee,
            'price' => $this->cursorOrder->price,
            'base_currency' => $this->cursorQuantity,
            'quote_currency' => math_multiply($this->cursorQuantity, $this->cursorOrder->price),
        ];

        // Cursor order transaction
        $cursorTransactions = [
            'platform_pool_id' => $this->cursorOrder->platform_pool_id,
            'is_maker' => true,
            'process_id' => $this->process_id,
            'order_id' => $this->cursorOrder->id ?? null,
            'user_id' => $this->cursorOrder->user_id ?? null,
            'market_id' => $this->order->market->id,
            'order_type' => $this->cursorOrder->type,
            'order_side' => $this->cursorOrder->side,
            'fee' => $this->cursorFee,
            'referral_fee' => $referralCursorFee,
            'price' => $this->cursorOrder->price,
            'base_currency' => $this->cursorQuantity,
            'quote_currency' => math_multiply($this->cursorQuantity, $this->cursorOrder->price),
        ];

        $transaction = (new Transaction)->create($transactions);
        $cursorTransaction = (new Transaction)->create($cursorTransactions);

        // 当前订单用户手续费减免返还，直接返还到交易账户
        $this->refundSpotFeeToUser(
            $this->order->user,
            $this->order->walletQuote,
            $transaction,
            $this->order,
            $this->fee,
            $orderDiscount,
            $orderVip,
            'order'
        );

        // 对手订单用户手续费减免返还，直接返还到交易账户
        if ($this->cursorOrder->id && $this->cursorOrder->user && $walletQuote) {
            $this->refundSpotFeeToUser(
                $this->cursorOrder->user,
                $walletQuote,
                $cursorTransaction,
                $this->cursorOrder,
                $this->cursorFee,
                $cursorDiscount,
                $cursorVip,
                'cursor'
            );
        }

        $rewards = app(\App\Services\Referral\ExchangeRewards::class);
        $transaction->referral_fee = $rewards->record('spot', (string)$transaction->id, (string)$transaction->id,
            (int)$this->order->user_id, (int)$this->order->quote_currency_id, (string)$this->fee,
            $orderReferralDomain);
        $transaction->save();
        if ($this->cursorOrder->id && $this->cursorOrder->user) {
            $cursorTransaction->referral_fee = $rewards->record('spot', (string)$cursorTransaction->id, (string)$cursorTransaction->id,
                (int)$this->cursorOrder->user_id, (int)$this->cursorOrder->quote_currency_id, (string)$this->cursorFee,
                $cursorReferralDomain);
            $cursorTransaction->save();
        }

        // Revert pending amount if buyer entered bigger rate than seller's sell rate
        // In this case the system allow to buy from seller's rate and revert pending amount to buyer's wallet

        if ($this->isOrderPriceGreater) {
            $baseQuantity = $this->isBuyMarket ? $this->cursorQuantity : $this->filledQuantity;

            $actualDeductedAmount = math_multiply($baseQuantity, $this->order->price);
            $shouldDeductedAmount = math_multiply($baseQuantity, $this->cursorOrder->price);

            $shouldDeductedRevert = math_sub($actualDeductedAmount, $shouldDeductedAmount);

            if (!$this->isBuyMarket) {
                $revertedFee = math_percentage($shouldDeductedRevert, $orderfee_rate);
                $shouldDeductedRevert = math_sum($shouldDeductedRevert, $revertedFee);
            }

            if ($domain === 'virtual') $this->moveVirtualReserved($this->order->walletQuote,$shouldDeductedRevert,$this->order->walletQuote,$shouldDeductedRevert);
            else $this->walletService->revert($this->order->walletQuote, $shouldDeductedRevert);
        }

        // Set filled if quantity is zero
        if (($this->isBuyMarket && $this->order->quote_quantity <= 0) || (!$this->isBuyMarket && $this->order->quantity <= 0)) {
            $this->order->removeFromQueue(ORDER_STATUS_FILLED);
        }

        // Set filled cursor order if quantity is zero
        if ($this->cursorOrder->id && $this->cursorOrder->quantity <= 0) {
            $this->cursorOrder->removeFromQueue(ORDER_STATUS_FILLED);
        } elseif (!$this->cursorOrder->id && $this->cursorOrder->quantity && !\App\Services\Market\StockAssets::supports($this->order->market->name)) {

            // Remove liquidity order from the market orderbook
            try {

                $liquiditySide = order_is_buy($this->cursorOrder->side) ? 'asks' : 'bids';

                $liquidity = Cache::get("markets_liquidity.{$this->order->market->name}.{$liquiditySide}");

                if ($liquidity) {

                    $key = $liquidity->search(function ($item) {
                        return $item['price'] == $this->cursorOrder->price;
                    });

                    if ($key !== false) {
                        $liquidity->forget($key);
                        Cache::put("markets_liquidity.{$this->order->market->name}.{$liquiditySide}", $liquidity);
                    }

                }

            } catch (\Exception $e) {
                Log::error($e);
                Log::info('Trying to remove liquidity order from the queue');
                Log::info($this->cursorOrder);
            }
        }

        // Cache Market Values (reuse cached service instance)
        $marketModel=$this->order->market;
        $settledPrice=(string)$this->cursorOrder->price;
        $settledQuantity=(string)$this->cursorQuantity;
        DB::afterCommit(function()use($marketModel,$settledPrice,$settledQuantity){
            (new MarketService())->updateStats($marketModel->id,$settledPrice,$settledQuantity,math_multiply($settledQuantity,$settledPrice));
            app(\App\Services\Market\TickerFreshness::class)->received($marketModel->id,'deepro:matched-trade',now()->timestamp);
        });

        // Trigger stop limit orders if any (async dispatch for better performance)
        ProcessStopLimitOrdersJob::dispatch($this->order->market->id)->onQueue('{okrcoin}:orders');

        // Dispatch market stats event
        DB::afterCommit(fn()=>event(new MarketStatsUpdated($marketModel)));

        // Batch trade events
        if ($this->order->user) {
            DB::afterCommit(fn()=>event(new MarketTradeUpdated($transaction, false)));
            DB::afterCommit(fn()=>event(new MarketTradePrivateUpdated($transaction, false)));
        }

        if (isset($this->cursorOrder->user)) {
            DB::afterCommit(fn()=>event(new MarketTradeUpdated($cursorTransaction, true)));
            DB::afterCommit(fn()=>event(new MarketTradePrivateUpdated($cursorTransaction, false)));
        }

        // Collect unique wallets to update (avoid duplicate events)
        $walletsToUpdate = [];

        if ($this->order->walletBase) {
            $walletsToUpdate[$this->order->walletBase->id] = $this->order->walletBase;
        }

        if ($this->order->walletQuote) {
            $walletsToUpdate[$this->order->walletQuote->id] = $this->order->walletQuote;
        }

        if ($this->cursorOrder->id) {
            if ($this->cursorOrder->walletBase) {
                $walletsToUpdate[$this->cursorOrder->walletBase->id] = $this->cursorOrder->walletBase;
            }

            if ($this->cursorOrder->walletQuote) {
                $walletsToUpdate[$this->cursorOrder->walletQuote->id] = $this->cursorOrder->walletQuote;
            }
        }

        // Dispatch wallet events (deduplicated)
        foreach ($walletsToUpdate as $wallet) {
            DB::afterCommit(fn()=>event(new WalletUpdated($wallet)));
        }
    }

    private function referralFundingDomain($order): string
    {
        if (in_array($order->settlement_domain, ['real','virtual'], true)) return $order->settlement_domain;
        // Capture before settlement consumes the locked balance, matching the legacy virtual settlement path.
        $wallet = order_is_buy($order->side) ? $order->walletQuote : $order->walletBase;
        if ($order->user->is_xn || $order->user->is_xm || ($wallet && bccomp((string)($wallet->balance_in_virtual_order ?? '0'),'0',18)>0)) return 'virtual';
        return $wallet ? 'real' : 'unknown';
    }

    public function reflectBalances($wallet, $quantity, $walletQuote, $quoteQuantity)
    {
        if (!$wallet || !$walletQuote || DB::transactionLevel() === 0) throw new \RuntimeException('SPOT_WALLETS_REQUIRED');
        $domain = \App\Services\Order\SpotFunding::domain($this->order);
        $locked = \App\Models\Wallet\Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $field = $domain === 'virtual' ? 'balance_in_virtual_order' : 'balance_in_order';
        if (bccomp((string)$quantity, '0', 18) <= 0 || bccomp((string)$quoteQuantity, '0', 18) < 0 || bccomp((string)$locked->{$field}, (string)$quantity, 18) < 0) {
            throw new \RuntimeException('SPOT_RESERVED_BALANCE_INSUFFICIENT');
        }
        if ($domain === 'virtual') {
            $this->moveVirtualReserved($wallet,$quantity,$walletQuote,$quoteQuantity);
        } else {
            $this->walletService->decrease($wallet, $quantity);
            $this->walletService->increase($walletQuote, $quoteQuantity, 'trade');
        }
    }

    private function moveVirtualReserved($debit,string $amount,$credit,string $received): void
    {
        if (bccomp($amount,'0',18)<0 || bccomp($received,'0',18)<0) throw new \RuntimeException('Invalid virtual settlement amount');
        if (DB::update('UPDATE wallets SET balance_in_virtual_order=balance_in_virtual_order-?, updated_at=? WHERE id=? AND balance_in_virtual_order>=?',[$amount,now(),$debit->id,$amount])!==1) throw new \RuntimeException('SPOT_RESERVED_BALANCE_INSUFFICIENT');
        DB::update('UPDATE wallets SET balance_in_virtual_trade=COALESCE(balance_in_virtual_trade,0)+?, updated_at=? WHERE id=?',[$received,now(),$credit->id]);
    }

    protected function getVipFeeDiscount($vip)
    {
        $vip = intval($vip ?? 0);

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

    protected function refundSpotFeeToUser(
        $user,
        $wallet,
        $transaction,
        $order,
        $originalFee,
        $discount,
        $vip,
        string $feeRole
    ) {
        if (!$user || !$wallet || !$transaction || !$order) {
            return '0';
        }

        $originalFee = $this->safeDecimal($originalFee);

        if ($this->safeCompare($originalFee, 0) <= 0) {
            return '0';
        }

        $refundRate = $this->getRefundRateByDiscount($discount);

        if ($this->safeCompare($refundRate, 0, 8) <= 0) {
            return '0';
        }

        $refundAmount = $this->safeDecimal(
            math_formatter(math_percentage($originalFee, $refundRate), 8)
        );

        if ($this->safeCompare($refundAmount, 0) <= 0) {
            return '0';
        }

        // 手续费减免部分直接返还到交易账户
        $this->walletService->increase($wallet, $refundAmount, 'trade');

        $this->createSpotFeeRefundRecord(
            $user,
            $wallet,
            $transaction,
            $order,
            $originalFee,
            $discount,
            $refundRate,
            $refundAmount,
            $vip,
            $feeRole
        );

        return $refundAmount;
    }

    protected function createSpotFeeRefundRecord(
        $user,
        $wallet,
        $transaction,
        $order,
        $originalFee,
        $discount,
        $refundRate,
        $refundAmount,
        $vip,
        string $feeRole
    ) {
        DB::table('spot_fee_refund_records')->insert([
            'id' => generate_uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id ?? null,
            'transaction_id' => (string) $transaction->id,
            'order_id' => (string) ($order->id ?? ''),
            'market_id' => $order->market_id ?? ($order->market->id ?? null),
            'currency_id' => $order->quote_currency_id,
            'fee_role' => $feeRole,
            'original_fee' => $this->safeDecimal($originalFee),
            'vip_level' => intval($vip ?? 0),
            'discount_rate' => $this->safeDecimal($discount, 8),
            'refund_rate' => $this->safeDecimal($refundRate, 8),
            'refund_amount' => $this->safeDecimal($refundAmount),
            'remark' => $feeRole === 'order'
                ? 'Spot order fee refund'
                : 'Spot matched order fee refund',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function safeDecimal($value, $scale = 18)
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }

        if (!is_numeric($value)) {
            return '0';
        }

        $floatValue = (float) $value;

        if (is_nan($floatValue) || is_infinite($floatValue)) {
            return '0';
        }

        $number = number_format($floatValue, $scale, '.', '');
        $number = rtrim($number, '0');
        $number = rtrim($number, '.');

        return ($number === '' || $number === '-0') ? '0' : $number;
    }

    private function safeCompare($left, $right, $scale = 18)
    {
        return bccomp($this->safeDecimal($left, $scale), $this->safeDecimal($right, $scale), $scale);
    }
}
