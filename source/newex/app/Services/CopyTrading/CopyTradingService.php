<?php

namespace App\Services\CopyTrading;

use App\Models\CopyTrading\CopyTradingCopiedOrder;
use App\Models\CopyTrading\CopyTradingFollow;
use App\Models\CopyTrading\CopyTradingTrader;
use App\Models\Currency\Currency;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Order\OrderRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CopyTradingService
{
    public function copyFuturesOpen(string $sourceContractId, array $sourcePayload = []): array
    {
        $sourceContract = FuturesContract::query()
            ->with('market')
            ->where('id', $sourceContractId)
            ->first();

        if (!$sourceContract || !$sourceContract->market || !in_array($sourceContract->status,['active','pending','scheduled'],true)) {
            return [
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        $copyTrader = CopyTradingTrader::query()
            ->enabled()
            ->where('user_id', $sourceContract->user_id)
            ->first();

        if (!$copyTrader) {
            return [
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        $follows = CopyTradingFollow::query()
            ->enabled()
            ->with('follower')
            ->where('copy_trading_trader_id', $copyTrader->id)
            ->get();

        if ($follows->isEmpty()) {
            return [
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        $payload = $this->buildCopyPayload($sourceContract, $sourcePayload);
        $originalUser = Auth::user();
        $originalInput = request()->all();

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($follows as $follow) {
            $stats['processed']++;
            $attemptPayload = $payload;

            try {
                $outcome = DB::transaction(function () use ($follow, $sourceContract, $payload, &$attemptPayload) {
                    $currentSource=FuturesContract::whereKey($sourceContract->id)->lockForUpdate()->first();
                    if (!$currentSource || !in_array($currentSource->status,['active','pending','scheduled'],true)) return 'skipped';
                    $lockedFollow = CopyTradingFollow::whereKey($follow->id)->lockForUpdate()->first();
                    if (!$lockedFollow || !$lockedFollow->is_enabled) return 'skipped';
                    $identity=['follow_id'=>$follow->id,'source_contract_id'=>(string)$sourceContract->id,'phase'=>'open'];
                    if (DB::table('copy_trading_events')->where($identity)->exists()) return 'skipped';
                    // Preserve all historical copies; never replay a successful legacy source event.
                    $legacy=CopyTradingCopiedOrder::where('copy_trading_follow_id',$follow->id)->where('source_contract_id',$sourceContract->id)->whereNotNull('follower_contract_id')->first();
                    if($legacy){DB::table('copy_trading_events')->insert($identity+['status'=>'legacy','result_id'=>$legacy->follower_contract_id,'created_at'=>now(),'updated_at'=>now()]);return 'skipped';}
                    $follower = $lockedFollow->follower;
                    $status='skipped';$reason='Follower unavailable';$contractId=null;$followerPayload=$payload;
                    if ($follower && (int)$follower->id !== (int)$sourceContract->user_id && !$follower->deactivated) {
                        $followerPayload = $this->buildFollowerPayload($follower,$payload);
                        $reason='Follower has no available futures funds';
                        if($followerPayload){
                            $attemptPayload=$followerPayload;
                            $contractId=$this->openFollowerPosition($follower,$followerPayload);
                            $status='success';$reason=null;
                        }
                    }
                    $this->recordCopiedOrder($lockedFollow,$sourceContract,$contractId,$status,$reason,$followerPayload?:$payload);
                    DB::table('copy_trading_events')->insert($identity+['status'=>$status,'result_id'=>$contractId,'created_at'=>now(),'updated_at'=>now()]);
                    return $status;
                },3);
                $stats[$outcome]++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->recordCopiedOrder($follow, $sourceContract, null, 'failed', $e->getMessage(), $attemptPayload);
                Log::warning('Copy trading futures open failed', [
                    'source_contract_id' => (string) $sourceContract->id,
                    'source_user_id' => (int) $sourceContract->user_id,
                    'follower_user_id' => (int) ($follow->follower_user_id ?? 0),
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Auth::setUser($originalUser);
                request()->replace($originalInput);
            }
        }

        return $stats;
    }

    public function copyFuturesClose(string $sourceContractId): array
    {
        $copiedOrders = CopyTradingCopiedOrder::query()
            ->where('source_contract_id', $sourceContractId)
            ->whereNotNull('follower_contract_id')
            ->whereIn('status', ['success', 'close_failed'])
            ->get()
            ->unique(function (CopyTradingCopiedOrder $copiedOrder) {
                return $copiedOrder->follower_user_id . ':' . $copiedOrder->follower_contract_id;
            })
            ->values();

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if ($copiedOrders->isEmpty()) {
            return $stats;
        }

        $originalUser = Auth::user();
        $originalInput = request()->all();

        foreach ($copiedOrders as $copiedOrder) {
            $stats['processed']++;

            try {
                $follower = User::find($copiedOrder->follower_user_id);

                if (!$follower || (bool) ($follower->deactivated ?? false)) {
                    $stats['skipped']++;
                    $this->markCopiedClose($sourceContractId, $copiedOrder, 'close_skipped', 'Follower unavailable');
                    continue;
                }

                $followerContract = FuturesContract::query()
                    ->where('id', $copiedOrder->follower_contract_id)
                    ->where('user_id', $copiedOrder->follower_user_id)
                    ->whereIn('status', ['active', 'pending'])
                    ->first();

                if (!$followerContract) {
                    $stats['skipped']++;
                    $this->markCopiedClose($sourceContractId, $copiedOrder, 'close_skipped', 'Follower contract is not open');
                    continue;
                }

                Auth::setUser($follower);
                request()->replace([
                    'uuid' => (string) $copiedOrder->follower_contract_id,
                    'copy_trading' => true,
                    'copy_trading_source_contract_id' => $sourceContractId,
                ]);

                $outcome=DB::transaction(function()use($sourceContractId,$copiedOrder){
                    $locked=CopyTradingCopiedOrder::whereKey($copiedOrder->id)->lockForUpdate()->first();
                    if(!$locked || !in_array($locked->status,['success','close_failed'],true))return 'skipped';
                    $closed=(bool)app(OrderRepository::class)->cancelFutures();
                    if(!$closed)throw new \RuntimeException('Follower close request was not processed');
                    $this->markCopiedClose($sourceContractId,$locked,'close_success',null);
                    return 'success';
                },3);
                $stats[$outcome]++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->markCopiedClose($sourceContractId, $copiedOrder, 'close_failed', $e->getMessage());
                Log::warning('Copy trading futures close failed', [
                    'source_contract_id' => $sourceContractId,
                    'follower_user_id' => (int) ($copiedOrder->follower_user_id ?? 0),
                    'follower_contract_id' => (string) ($copiedOrder->follower_contract_id ?? ''),
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Auth::setUser($originalUser);
                request()->replace($originalInput);
            }
        }

        return $stats;
    }

    protected function openFollowerPosition(User $follower, array $payload): string
    {
        // The persisted enabled follow authorizes this first-party server action; still validate all trading inputs.
        $follower->withAccessToken(new \Laravel\Sanctum\TransientToken());
        Auth::setUser($follower);
        request()->replace($payload);
        $validation=\App\Http\Requests\Api\Order\OrderFuturesStoreRequest::createFrom(request());
        $validation->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn()=>$follower);
        $validation->validateResolved();
        $header=request()->header('Idempotency-Key');
        request()->headers->remove('Idempotency-Key');
        try { return (string) app(OrderRepository::class)->store(true); }
        finally { if ($header !== null) request()->headers->set('Idempotency-Key',$header); }
    }

    protected function buildCopyPayload(FuturesContract $sourceContract, array $sourcePayload): array
    {
        $type = $sourcePayload['type'] ?? $sourceContract->type ?? FuturesContract::TYPE_MARKET;
        $sourceMargin = $this->sourcePayloadGrossMargin($sourceContract, $sourcePayload, $type);
        $sourceAvailableFunds = $this->futuresAvailableFunds(
            (int) $sourceContract->user_id,
            (int) $sourceContract->quote_currency_id
        );
        $copyMarginRatio = $this->copyMarginRatio($sourceMargin, $sourceAvailableFunds);

        $leverage=$sourceContract->leverage ?? ($sourcePayload['leverage'] ?? 1);
        if (is_numeric($leverage) && bccomp((string)$leverage,(string)(int)$leverage,8)===0) $leverage=(int)$leverage;
        $payload = [
            'market' => $sourcePayload['market'] ?? $sourceContract->market->name,
            'type' => $type,
            'side' => (bool) $sourceContract->is_long ? 'buy' : 'sell',
            'leverage' => $leverage,
            'copy_trading' => true,
            'copy_trading_source_contract_id' => (string) $sourceContract->id,
            'copy_trading_quote_currency_id' => (int) $sourceContract->quote_currency_id,
            'copy_trading_source_margin' => $sourceMargin,
            'copy_trading_source_available_funds' => $sourceAvailableFunds,
            'copy_trading_margin_ratio' => $copyMarginRatio,
        ];

        if ($type === FuturesContract::TYPE_MARKET) {
            $payload['quoteQuantity'] = $sourceMargin;
        } else {
            $payload['price'] = $sourcePayload['price'] ?? $sourceContract->price;
            $payload['quantity'] = $sourceMargin;
        }

        $takeProfitPrice = $sourcePayload['take_profit_price'] ?? $sourceContract->take_profit_price ?? null;
        $stopLossPrice = $sourcePayload['stop_loss_price'] ?? $sourceContract->stop_loss_price ?? null;

        if ($takeProfitPrice || $stopLossPrice) {
            $payload['enable_tp_sl'] = true;

            if ($takeProfitPrice) {
                $payload['take_profit_price'] = $takeProfitPrice;
            }

            if ($stopLossPrice) {
                $payload['stop_loss_price'] = $stopLossPrice;
            }
        }

        return $payload;
    }

    protected function buildFollowerPayload(User $follower, array $payload): ?array
    {
        $ratio = $this->decimal($payload['copy_trading_margin_ratio'] ?? 0);
        $quoteCurrencyId = (int) ($payload['copy_trading_quote_currency_id'] ?? 0);

        if ($quoteCurrencyId <= 0 || math_compare($ratio, 0) <= 0) {
            return null;
        }

        $followerFunds = $this->futuresAvailableFunds((int) $follower->id, $quoteCurrencyId);

        if (math_compare($followerFunds, 0) <= 0) {
            return null;
        }

        $precision=(int)\App\Models\Market\Market::where('name',$payload['market'])->value('quote_precision');
        $followerMargin = bcadd($this->decimal(math_multiply($followerFunds, $ratio)),'0',max(0,min(18,$precision)));

        if (math_compare($followerMargin, 0) <= 0) {
            return null;
        }

        $payload['copy_trading_follower_available_funds'] = $followerFunds;
        $payload['copy_trading_follower_margin'] = $followerMargin;

        if (($payload['type'] ?? FuturesContract::TYPE_MARKET) === FuturesContract::TYPE_MARKET) {
            $payload['quoteQuantity'] = $followerMargin;
        } else {
            $payload['quantity'] = $followerMargin;
        }

        return $payload;
    }

    protected function sourcePayloadGrossMargin(FuturesContract $sourceContract, array $sourcePayload, string $type): string
    {
        $payloadAmount = $type === FuturesContract::TYPE_MARKET
            ? ($sourcePayload['quoteQuantity'] ?? null)
            : ($sourcePayload['quantity'] ?? null);

        if ($payloadAmount !== null && $payloadAmount !== '' && math_compare($this->decimal($payloadAmount), 0) > 0) {
            return $this->decimal($payloadAmount);
        }

        return $this->sourceGrossMargin($sourceContract);
    }

    protected function copyMarginRatio(string $sourceMargin, string $sourceAvailableFunds): string
    {
        $sourceMargin = $this->decimal($sourceMargin);
        $sourceAvailableFunds = $this->decimal($sourceAvailableFunds);

        if (math_compare($sourceMargin, 0) <= 0) {
            return '0';
        }

        $sourceFundsBeforeOpen = $this->decimal(math_sum($sourceAvailableFunds, $sourceMargin));

        if (math_compare($sourceFundsBeforeOpen, 0) <= 0) {
            return '0';
        }

        $ratio = $this->decimal(math_divide($sourceMargin, $sourceFundsBeforeOpen, 18));

        return math_compare($ratio, '1') > 0 ? '1' : $ratio;
    }

    protected function sourceGrossMargin(FuturesContract $sourceContract): string
    {
        $balance = $sourceContract->balance ?? 0;
        $entryFee = $sourceContract->entry_fee ?? 0;

        return $this->decimal(math_sum($balance, $entryFee));
    }

    protected function futuresAvailableFunds(int $userId, int $quoteCurrencyId): string
    {
        if ($userId <= 0 || $quoteCurrencyId <= 0) {
            return '0';
        }

        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency_id', $quoteCurrencyId)
            ->first();

        $tradeBalance = $wallet ? $this->decimal($wallet->balance_in_trade ?? 0) : '0';
        $virtualTradeBalance = ($wallet && Schema::hasColumn('wallets', 'balance_in_virtual_trade'))
            ? $this->decimal($wallet->balance_in_virtual_trade ?? 0)
            : '0';
        $autoInvestAvailable = $this->autoInvestAvailableForFutures($userId, $quoteCurrencyId);

        if ($this->isFuturesUserVirtual($userId) || math_compare($virtualTradeBalance, 0) > 0) {
            return $this->decimal(math_sum($virtualTradeBalance, $autoInvestAvailable));
        }

        return $this->decimal(math_sum($tradeBalance, $autoInvestAvailable));
    }

    protected function autoInvestAvailableForFutures(int $userId, int $quoteCurrencyId): string
    {
        if (!Schema::hasTable('auto_invest_orders')) {
            return '0';
        }

        $hasUsedMargin = Schema::hasColumn('auto_invest_orders', 'used_margin');
        $amountExpression = $hasUsedMargin
            ? 'SUM(GREATEST(COALESCE(amount, 0) - COALESCE(used_margin, 0), 0)) as available_amount'
            : 'SUM(COALESCE(amount, 0)) as available_amount';

        $query = DB::table('auto_invest_orders')
            ->selectRaw('currency_id, ' . $amountExpression)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->groupBy('currency_id');

        if ($hasUsedMargin) {
            $query->whereRaw('(COALESCE(amount, 0) - COALESCE(used_margin, 0)) > 0');
        } else {
            $query->whereRaw('COALESCE(amount, 0) > 0');
        }

        $available = '0';

        foreach ($query->get() as $order) {
            $orderCurrencyId = (int) ($order->currency_id ?? 0);
            $orderAmount = $this->decimal($order->available_amount ?? 0);

            if ($orderCurrencyId <= 0 || math_compare($orderAmount, 0) <= 0) {
                continue;
            }

            $available = $this->decimal(math_sum(
                $available,
                $this->convertAmountToQuoteCurrency($orderAmount, $orderCurrencyId, $quoteCurrencyId)
            ));
        }

        return $available;
    }

    protected function convertAmountToQuoteCurrency(string $amount, int $fromCurrencyId, int $quoteCurrencyId): string
    {
        $amount = $this->decimal($amount);

        if (math_compare($amount, 0) <= 0 || $fromCurrencyId <= 0 || $quoteCurrencyId <= 0) {
            return '0';
        }

        if ($fromCurrencyId === $quoteCurrencyId) {
            return $amount;
        }

        $fromRate = $this->currencyToUsdRate($fromCurrencyId);
        $quoteRate = $this->currencyToUsdRate($quoteCurrencyId);

        if (math_compare($fromRate, 0) <= 0 || math_compare($quoteRate, 0) <= 0) {
            return '0';
        }

        return $this->decimal(math_divide(math_multiply($amount, $fromRate), $quoteRate));
    }

    protected function currencyToUsdRate(int $currencyId): string
    {
        static $cache = [];

        if ($currencyId <= 0) {
            return '0';
        }

        if (isset($cache[$currencyId])) {
            return $cache[$currencyId];
        }

        $currency = Currency::query()->find($currencyId);

        if (!$currency) {
            return $cache[$currencyId] = '0';
        }

        $rate = (new CurrencyRepository())->currencyPriceInUsd($currency);

        return $cache[$currencyId] = $this->decimal($rate);
    }

    protected function isFuturesUserVirtual(int $userId): bool
    {
        if ($userId <= 0 || !Schema::hasColumn('users', 'is_xn')) {
            return false;
        }

        return DB::table('users')
            ->where('id', $userId)
            ->where('is_xn', true)
            ->exists();
    }

    protected function decimal($value, int $scale = 18): string
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return '0';
        }

        if (!is_numeric((string) $value)) {
            return '0';
        }

        if (stripos((string) $value, 'e') !== false) {
            $value = rtrim(rtrim(sprintf('%.18F', (float) $value), '0'), '.');

            if ($value === '') {
                $value = '0';
            }
        }

        return math_formatter((string) $value, $scale);
    }

    protected function recordCopiedOrder(
        CopyTradingFollow $follow,
        FuturesContract $sourceContract,
        ?string $followerContractId,
        string $status,
        ?string $errorMessage,
        array $payload
    ): void {
        CopyTradingCopiedOrder::create([
            'copy_trading_follow_id' => $follow->id,
            'source_user_id' => $sourceContract->user_id,
            'follower_user_id' => $follow->follower_user_id,
            'source_contract_id' => (string) $sourceContract->id,
            'follower_contract_id' => $followerContractId,
            'status' => $status,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 1000) : null,
            'payload' => $payload,
        ]);
    }

    protected function markCopiedClose(
        string $sourceContractId,
        CopyTradingCopiedOrder $copiedOrder,
        string $status,
        ?string $errorMessage
    ): void {
        CopyTradingCopiedOrder::query()
            ->where('source_contract_id', $sourceContractId)
            ->where('follower_user_id', $copiedOrder->follower_user_id)
            ->where('follower_contract_id', $copiedOrder->follower_contract_id)
            ->update([
                'status' => $status,
                'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 1000) : null,
                'updated_at' => now(),
            ]);
    }
}
