<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\CopyTrading\CopyTradingCopiedOrder;
use App\Models\CopyTrading\CopyTradingFollow;
use App\Models\CopyTrading\CopyTradingTrader;
use App\Models\Currency\Currency;
use App\Models\Order\FuturesContract;
use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class CopyTradingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $followingTraderIds = $user
            ? CopyTradingFollow::query()
                ->enabled()
                ->where('follower_user_id', $user->id)
                ->pluck('copy_trading_trader_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $traders = CopyTradingTrader::query()->published()
            ->enabled()
            ->with('user:id,email,referral_code,nickname,leader_nickname')
            ->withCount(['activeFollows as followers_count'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (CopyTradingTrader $trader) use ($followingTraderIds, $user) {
                return $this->formatTrader($trader, $followingTraderIds, $user ? (int) $user->id : null);
            })
            ->values();

        return Inertia::render('CopyTrading/Index', [
            'traders' => $traders,
            // A withdrawn listing must not remove the follower's exit control.
            'unpublishedFollowing' => CopyTradingTrader::whereIn('id', $followingTraderIds)->get()
                ->filter(fn ($trader) => !$trader->is_enabled || !$trader->isPublished())
                ->map(fn ($trader) => ['id' => $trader->id, 'name' => $trader->displayName(), 'is_following' => true])->values(),
            'portfolio' => $this->formatPortfolio($user),
            'stats' => [
                'trader_count' => $traders->count(),
                'active_orders_count' => $traders->sum('active_orders_count'),
                'recent_orders_count' => $traders->sum('recent_orders_count'),
            ],
        ]);
    }

    public function show(Request $request, CopyTradingTrader $copyTrader)
    {
        if (!$copyTrader->is_enabled || !$copyTrader->isPublished()) {
            abort(404);
        }

        $user = $request->user();
        $followingTraderIds = $user
            ? CopyTradingFollow::query()
                ->enabled()
                ->where('follower_user_id', $user->id)
                ->pluck('copy_trading_trader_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $copyTrader->load('user:id,email,referral_code,nickname,leader_nickname')
            ->loadCount(['activeFollows as followers_count']);

        $trader = $this->formatTrader($copyTrader, $followingTraderIds, $user ? (int) $user->id : null, true);
        $trader['followers'] = $this->formatFollowers($copyTrader);

        return Inertia::render('CopyTrading/Show', [
            'trader' => $trader,
        ]);
    }

    public function follow(Request $request, CopyTradingTrader $copyTrader)
    {
        $user = $request->user();

        if (!$user) {
            return Redirect::route('login');
        }

        if (!$copyTrader->is_enabled || !$copyTrader->isPublished()) {
            return Redirect::back()->with('error', __('当前策略员暂未开放跟单。'));
        }

        if ((int) $copyTrader->user_id === (int) $user->id) {
            return Redirect::back()->with('error', __('不能跟单自己的策略。'));
        }

        CopyTradingFollow::updateOrCreate(
            [
                'copy_trading_trader_id' => $copyTrader->id,
                'follower_user_id' => $user->id,
            ],
            [
                'trader_user_id' => $copyTrader->user_id,
                'is_enabled' => true,
            ]
        );

        return Redirect::back()->with('success', __('已开始跟单。'));
    }

    public function unfollow(Request $request, CopyTradingTrader $copyTrader)
    {
        $user = $request->user();

        if (!$user) {
            return Redirect::route('login');
        }

        CopyTradingFollow::query()
            ->where('copy_trading_trader_id', $copyTrader->id)
            ->where('follower_user_id', $user->id)
            ->update(['is_enabled' => false]);

        return Redirect::back()->with('success', __('已取消跟单。'));
    }

    protected function formatTrader(CopyTradingTrader $trader, array $followingTraderIds = [], ?int $currentUserId = null, bool $includeDetails = false): array
    {
        $baseQuery = FuturesContract::query()
            ->with(['market.baseCurrency', 'market.quoteCurrency'])
            ->where('user_id', $trader->user_id)
            ->whereHas('market');

        $activeOrdersCount = (clone $baseQuery)
            ->where('status', 'active')
            ->count();

        $recentOrders = (clone $baseQuery)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(function (FuturesContract $contract) {
                return $this->formatOrder($contract);
            })
            ->values();

        $historyOrders = collect();
        $operationOrders = collect();

        if ($includeDetails) {
            $historyOrders = (clone $baseQuery)
                ->where('status', '!=', 'active')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get()
                ->map(function (FuturesContract $contract) {
                    return $this->formatOrder($contract);
                })
                ->values();

            $operationOrders = $this->formatOperationEvents($baseQuery);
        }

        $lastOrder = $recentOrders->first();
        $actualFollowersCount = (int) ($trader->followers_count ?? 0);
        $displayFollowersCount = (int) $this->traderValue($trader, 'display_followers_count', 0);
        $sectionLabel = trim((string) $this->traderValue($trader, 'section_label', ''));

        return [
            'id' => $trader->id,
            'name' => $trader->displayName(),
            'strategy_label' => $trader->strategy_label ?: __('精选合约策略'),
            'section_label' => $sectionLabel ?: __('高盈亏'),
            'active_orders_count' => $activeOrdersCount,
            'closed_orders_count' => (int) $this->traderValue($trader, 'history_orders_count', 0),
            'recent_orders_count' => $recentOrders->count(),
            'win_rate' => $this->decimal($this->traderValue($trader, 'win_rate', 0), 2),
            'followers_count' => $displayFollowersCount > 0 ? $displayFollowersCount : $actualFollowersCount,
            'actual_followers_count' => $actualFollowersCount,
            'followers_limit' => (int) $this->traderValue($trader, 'display_followers_limit', 0),
            'badges' => $this->parseList($this->traderValue($trader, 'display_badges', '')),
            'profit_amount' => $this->decimal($this->traderValue($trader, 'display_profit_amount', 0), 2),
            'roi_percent' => $this->decimal($this->traderValue($trader, 'display_roi_percent', 0), 2),
            'asset_scale' => $this->decimal($this->traderValue($trader, 'display_asset_scale', 0), 2),
            'max_drawdown' => $this->decimal($this->traderValue($trader, 'display_max_drawdown', 0), 2),
            'lead_days' => (int) $this->traderValue($trader, 'display_lead_days', 0),
            'chart_points' => $this->parseChartPoints($this->traderValue($trader, 'display_chart_points', '')),
            'is_following' => in_array((int) $trader->id, $followingTraderIds, true),
            'can_follow' => $currentUserId !== null && (int) $trader->user_id !== $currentUserId,
            'is_self' => $currentUserId !== null && (int) $trader->user_id === $currentUserId,
            'last_order_at' => is_array($lastOrder) ? ($lastOrder['opened_at'] ?? null) : null,
            'orders' => $recentOrders,
            'history_orders' => $historyOrders,
            'operation_orders' => $operationOrders,
        ];
    }

    protected function formatOperationEvents($baseQuery)
    {
        $events = collect();

        (clone $baseQuery)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->each(function (FuturesContract $contract) use ($events) {
                $events->push($this->formatOperationEvent($contract, 'open'));

                if ($contract->status !== 'active') {
                    $events->push($this->formatOperationEvent($contract, 'close'));
                }
            });

        return $events
            ->filter()
            ->sortByDesc('event_sort')
            ->take(10)
            ->map(function (array $event) {
                unset($event['event_sort']);

                return $event;
            })
            ->values();
    }

    protected function formatOperationEvent(FuturesContract $contract, string $kind): ?array
    {
        $market = $contract->market;
        $eventAt = $kind === 'close' ? $contract->updated_at : $contract->created_at;

        if (!$eventAt || !$market) {
            return null;
        }

        $basePrecision = (int) ($market->base_precision ?? 4);
        $quotePrecision = (int) ($market->quote_precision ?? 4);
        $quantity = (float) ($contract->quantity ?: 0);
        $price = $kind === 'close'
            ? (float) ($contract->close_price ?: $contract->price)
            : (float) ($contract->price ?: 0);
        $notionalValue = $quantity * $price;
        $isLong = (bool) $contract->is_long;
        $actionLabel = ($kind === 'close' ? ($isLong ? __('Close long') : __('Close short')) : ($isLong ? __('Open long') : __('Open short')));
        $actionTone = (($kind === 'open' && $isLong) || ($kind === 'close' && !$isLong)) ? 'green' : 'red';
        $tradeVerb = $kind === 'close'
            ? ($isLong ? __('卖出') : __('买入'))
            : ($isLong ? __('买入') : __('卖出'));
        $pnlAmount = $kind === 'close' ? (float) ($contract->pnl ?: 0) : 0;

        return [
            'id' => $contract->id . '-' . $kind,
            'contract_id' => $contract->id,
            'market' => $market->name,
            'contract_label' => $market->name . __('永续合约'),
            'base_symbol' => optional($market->baseCurrency)->symbol,
            'quote_symbol' => optional($market->quoteCurrency)->symbol,
            'side' => $isLong ? 'long' : 'short',
            'action_kind' => $kind,
            'action_label' => $actionLabel,
            'action_tone' => $actionTone,
            'trade_verb' => $tradeVerb,
            'price' => $this->decimal($price, $quotePrecision),
            'quantity' => $this->decimal($quantity, $basePrecision),
            'notional_value' => $this->decimal($notionalValue, $quotePrecision),
            'pnl_amount' => $this->decimal($pnlAmount, $quotePrecision),
            'is_profitable' => $pnlAmount >= 0,
            'event_at' => optional($eventAt)->format('Y-m-d H:i:s'),
            'event_date' => optional($eventAt)->format('Y-m-d'),
            'event_time' => optional($eventAt)->format('H:i:s'),
            'event_sort' => optional($eventAt)->getTimestamp(),
        ];
    }

    protected function formatPortfolio($user): array
    {
        $empty = [
            'active_orders_count' => 0,
            'pending_orders_count' => 0,
            'total_orders_count' => 0,
            'total_balance_usdt' => '0.00',
            'total_margin_usdt' => '0.00',
            'used_margin_usdt' => '0.00',
            'unrealized_pnl_usdt' => '0.00',
            'unrealized_pnl_percent' => '0.00',
            'orders' => [],
        ];

        if (!$user) {
            return $empty;
        }

        $copiedOrders = CopyTradingCopiedOrder::query()
            ->with(['follow.trader.user:id,email,referral_code,nickname,leader_nickname'])
            ->where('follower_user_id', $user->id)
            ->whereNotNull('follower_contract_id')
            ->orderByDesc('id')
            ->get();

        if ($copiedOrders->isEmpty()) {
            return $empty;
        }

        $copiedOrdersByContract = $copiedOrders
            ->filter(fn (CopyTradingCopiedOrder $order) => trim((string) $order->follower_contract_id) !== '')
            ->unique(fn (CopyTradingCopiedOrder $order) => (string) $order->follower_contract_id)
            ->keyBy(fn (CopyTradingCopiedOrder $order) => (string) $order->follower_contract_id);

        $contractIds = $copiedOrdersByContract->keys()->values()->all();

        if (empty($contractIds)) {
            return $empty;
        }

        $contracts = FuturesContract::query()
            ->with(['market.baseCurrency', 'market.quoteCurrency'])
            ->where('user_id', $user->id)
            ->whereIn('id', $contractIds)
            ->whereIn('status', ['active', 'pending'])
            ->whereHas('market')
            ->orderByDesc('created_at')
            ->get();

        if ($contracts->isEmpty()) {
            return $empty;
        }

        $usedMarginUsd = '0';
        $unrealizedPnlUsd = '0';
        $activeMarginUsd = '0';

        foreach ($contracts as $contract) {
            $quoteCurrencyId = (int) ($contract->quote_currency_id ?: optional($contract->market)->quote_currency_id);
            $marginUsd = $this->amountToUsd($this->contractUsedMargin($contract), $quoteCurrencyId);
            $usedMarginUsd = math_sum($usedMarginUsd, $marginUsd);

            if ($contract->status === 'active') {
                $activeMarginUsd = math_sum($activeMarginUsd, $marginUsd);
                $unrealizedPnlUsd = math_sum(
                    $unrealizedPnlUsd,
                    $this->amountToUsd($this->contractUnrealizedPnlAmount($contract), $quoteCurrencyId)
                );
            }
        }

        $unrealizedPnlPercent = math_compare($activeMarginUsd, 0) > 0
            ? math_multiply(math_divide($unrealizedPnlUsd, $activeMarginUsd, 18), 100)
            : '0';
        $totalBalanceUsd = math_sum($usedMarginUsd, $unrealizedPnlUsd);

        return [
            'active_orders_count' => $contracts->where('status', 'active')->count(),
            'pending_orders_count' => $contracts->where('status', 'pending')->count(),
            'total_orders_count' => $contracts->count(),
            'total_balance_usdt' => $this->decimal($totalBalanceUsd, 2),
            'total_margin_usdt' => $this->decimal($usedMarginUsd, 2),
            'used_margin_usdt' => $this->decimal($usedMarginUsd, 2),
            'unrealized_pnl_usdt' => $this->decimal($unrealizedPnlUsd, 2),
            'unrealized_pnl_percent' => $this->decimal($unrealizedPnlPercent, 2),
            'orders' => $contracts
                ->map(function (FuturesContract $contract) use ($copiedOrdersByContract) {
                    return $this->formatPortfolioOrder(
                        $contract,
                        $copiedOrdersByContract->get((string) $contract->id)
                    );
                })
                ->values()
                ->all(),
        ];
    }

    protected function formatPortfolioOrder(FuturesContract $contract, ?CopyTradingCopiedOrder $copiedOrder = null): array
    {
        $market = $contract->market;
        $quotePrecision = (int) ($market->quote_precision ?? 4);
        $quoteCurrencyId = (int) ($contract->quote_currency_id ?: optional($market)->quote_currency_id);
        $marketPrice = $this->resolveMarketPrice($contract);
        $pnlPercent = $contract->status === 'active' ? $this->resolvePnlPercent($contract, $marketPrice) : 0;
        $pnlAmount = $contract->status === 'active' ? $this->contractUnrealizedPnlAmount($contract) : '0';
        $marginAmount = $this->contractUsedMargin($contract);
        $trader = optional(optional($copiedOrder)->follow)->trader;

        return [
            'id' => $contract->id,
            'source_contract_id' => $copiedOrder ? (string) $copiedOrder->source_contract_id : null,
            'trader_name' => $trader ? $trader->displayName() : '',
            'market' => $market->name,
            'base_symbol' => optional($market->baseCurrency)->symbol,
            'quote_symbol' => optional($market->quoteCurrency)->symbol,
            'side' => $contract->is_long ? 'long' : 'short',
            'side_label' => $contract->is_long ? __('做多') : __('做空'),
            'status' => $contract->status,
            'status_label' => $contract->status === 'active' ? __('进行中') : __('待开仓'),
            'leverage' => (int) $contract->leverage,
            'entry_price' => $this->decimal($contract->price, $quotePrecision),
            'market_price' => $this->decimal($marketPrice, $quotePrecision),
            'used_margin' => $this->decimal($marginAmount, $quotePrecision),
            'used_margin_usdt' => $this->decimal($this->amountToUsd($marginAmount, $quoteCurrencyId), 2),
            'unrealized_pnl' => $this->decimal($pnlAmount, $quotePrecision),
            'unrealized_pnl_usdt' => $this->decimal($this->amountToUsd($pnlAmount, $quoteCurrencyId), 2),
            'pnl_percent' => $this->decimal($pnlPercent, 2),
            'is_profitable' => math_compare($this->decimal($pnlAmount), 0) >= 0,
            'opened_at' => optional($contract->created_at)->format('Y-m-d H:i:s'),
        ];
    }

    protected function contractUsedMargin(FuturesContract $contract): string
    {
        $totalMargin = $this->decimal($contract->total_margin_amount ?? 0);

        if (math_compare($totalMargin, 0) > 0) {
            return $totalMargin;
        }

        return $this->decimal(math_sum(
            $this->decimal($contract->balance ?? 0),
            $this->decimal($contract->entry_fee ?? 0)
        ));
    }

    protected function contractUnrealizedPnlAmount(FuturesContract $contract): string
    {
        if ($contract->status !== 'active') {
            return '0';
        }

        $marketPrice = $this->resolveMarketPrice($contract);
        $pnlPercent = $this->resolvePnlPercent($contract, $marketPrice);

        return $this->decimal(math_percentage($contract->balance, $pnlPercent));
    }

    protected function amountToUsd($amount, int $currencyId): string
    {
        $amount = $this->decimal($amount);

        if (math_compare($amount, 0) === 0 || $currencyId <= 0) {
            return $amount;
        }

        $rate = $this->currencyToUsdRate($currencyId);

        if (math_compare($rate, 0) <= 0) {
            return $amount;
        }

        return $this->decimal(math_multiply($amount, $rate));
    }

    protected function currencyToUsdRate(int $currencyId): string
    {
        static $rates = [];

        if ($currencyId <= 0) {
            return '0';
        }

        if (array_key_exists($currencyId, $rates)) {
            return $rates[$currencyId];
        }

        $currency = Currency::query()->find($currencyId);

        if (!$currency) {
            return $rates[$currencyId] = '0';
        }

        return $rates[$currencyId] = $this->decimal(
            (new CurrencyRepository())->currencyPriceInUsd($currency)
        );
    }

    protected function formatOrder(FuturesContract $contract): array
    {
        $market = $contract->market;
        $basePrecision = (int) ($market->base_precision ?? 4);
        $quotePrecision = (int) ($market->quote_precision ?? 4);
        $marketPrice = $this->resolveMarketPrice($contract);
        $pnlPercent = $this->resolvePnlPercent($contract, $marketPrice);
        $pnlAmount = $contract->status === 'active'
            ? $this->decimal(math_percentage($contract->balance, $pnlPercent), $quotePrecision)
            : $this->decimal($contract->pnl, $quotePrecision);
        $quantity = (float) ($contract->quantity ?: 0);
        $entryPrice = (float) ($contract->price ?: 0);
        $notionalValue = $quantity * $entryPrice;
        $isOpenAction = $contract->status === 'active';
        $actionPrefix = $isOpenAction ? __('开') : __('平');

        return [
            'id' => $contract->id,
            'market' => $market->name,
            'contract_label' => $market->name . __('永续合约'),
            'base_symbol' => optional($market->baseCurrency)->symbol,
            'quote_symbol' => optional($market->quoteCurrency)->symbol,
            'side' => $contract->is_long ? 'long' : 'short',
            'side_label' => $contract->is_long ? __('做多') : __('做空'),
            'action_label' => $actionPrefix . ($contract->is_long ? __('多') : __('空')),
            'action_kind' => $isOpenAction ? 'open' : 'close',
            'status' => $contract->status,
            'status_label' => $contract->status === 'active'
                ? __('进行中')
                : ($contract->status === 'liquidated' ? __('已强平') : __('已平仓')),
            'quantity' => $this->decimal($quantity, $basePrecision),
            'notional_value' => $this->decimal($notionalValue, $quotePrecision),
            'entry_price' => $this->decimal($contract->price, $quotePrecision),
            'market_price' => $this->decimal($marketPrice, $quotePrecision),
            'leverage' => (int) $contract->leverage,
            'margin' => $this->decimal($contract->balance, $quotePrecision),
            'pnl_percent' => $this->decimal($pnlPercent, 2),
            'pnl_amount' => $pnlAmount,
            'is_profitable' => (float) $pnlPercent >= 0,
            'opened_at' => optional($contract->created_at)->format('Y-m-d H:i:s'),
            'closed_at' => $contract->status === 'active'
                ? null
                : optional($contract->updated_at)->format('Y-m-d H:i:s'),
        ];
    }

    protected function resolveMarketPrice(FuturesContract $contract): float
    {
        $market = $contract->market;
        $price = 0;

        if ($contract->status === 'active' && $market) {
            $price = (float) market_get_stats($market->id, 'last');
        }

        if ($price <= 0 && $contract->status === 'active' && $market) {
            $price = (float) ($market->last ?? 0);
        }

        if ($price <= 0 && $contract->status !== 'active') {
            $price = (float) ($contract->close_price ?? 0);
        }

        if ($price <= 0) {
            $price = (float) $contract->price;
        }

        return $price;
    }

    protected function resolvePnlPercent(FuturesContract $contract, float $marketPrice): float
    {
        if ($contract->status === 'active') {
            return (float) futures_pnl_calculate(
                $contract->quantity,
                $contract->price,
                $marketPrice,
                $contract->leverage,
                $contract->is_long
            );
        }

        $balance = (float) $contract->balance;

        if ($balance <= 0) {
            return 0;
        }

        return round(((float) $contract->pnl / $balance) * 100, 2);
    }

    protected function decimal($value, int $precision = 4): string
    {
        return number_format((float) ($value ?: 0), $precision, '.', '');
    }

    protected function parseList($value): array
    {
        return collect(preg_split('/[,，\n]+/', (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    protected function parseChartPoints($value): array
    {
        $points = collect(preg_split('/[,，\s]+/', (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter(fn ($item) => is_numeric($item))
            ->map(fn ($item) => (float) $item)
            ->values()
            ->all();

        return count($points) >= 2 ? $points : [];
    }

    protected function formatFollowers(CopyTradingTrader $trader): array
    {
        return CopyTradingFollow::query()
            ->enabled()
            ->with([
                'follower:id,email,referral_code,nickname,leader_nickname',
                'copiedOrders',
            ])
            ->where('copy_trading_trader_id', $trader->id)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(function (CopyTradingFollow $follow) {
                $follower = $follow->follower;
                $contractIds = $follow->copiedOrders
                    ->pluck('follower_contract_id')
                    ->map(fn ($id) => trim((string) $id))
                    ->filter(fn ($id) => $id !== '')
                    ->unique()
                    ->values()
                    ->all();
                $contracts = empty($contractIds)
                    ? collect()
                    : FuturesContract::query()
                        ->with(['market.baseCurrency', 'market.quoteCurrency'])
                        ->whereIn('id', $contractIds)
                        ->get();
                $margin = $contracts->sum(fn (FuturesContract $contract) => (float) ($contract->balance ?: 0));
                $profit = $contracts->sum(function (FuturesContract $contract) {
                    if ($contract->status === 'active') {
                        $marketPrice = $this->resolveMarketPrice($contract);
                        $pnlPercent = $this->resolvePnlPercent($contract, $marketPrice);

                        return (float) math_percentage($contract->balance, $pnlPercent);
                    }

                    return (float) ($contract->pnl ?: 0);
                });
                $roi = $margin > 0 ? ($profit / $margin) * 100 : 0;
                $runningDays = optional($follow->created_at)->diffInDays(now()) ?? 0;

                return [
                    'id' => $follow->id,
                    'email' => $this->maskEmail(optional($follower)->email ?: ('UID ' . $follow->follower_user_id)),
                    'name' => optional($follower)->nickname ?: optional($follower)->leader_nickname ?: '',
                    'margin' => $this->decimal($margin, 2),
                    'profit' => $this->decimal($profit, 2),
                    'roi' => $this->decimal($roi, 2),
                    'running_days' => max(1, (int) $runningDays),
                    'started_at' => optional($follow->created_at)->format('Y-m-d H:i:s'),
                ];
            })
            ->values()
            ->all();
    }

    protected function maskFollowerName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);

        if ($length <= 2) {
            return $name === '' ? __('用户****') : mb_substr($name, 0, 1) . '****';
        }

        if ($length <= 8) {
            return mb_substr($name, 0, 1) . '****' . mb_substr($name, -1);
        }

        return mb_substr($name, 0, 3) . '****' . mb_substr($name, -2);
    }

    protected function maskEmail(string $email): string
    {
        $email = trim($email);

        if (str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2);
            $hideLength = mb_strlen($local) > 10 ? 6 : 4;

            return $this->maskMiddle($local, $hideLength) . '@' . $domain;
        }

        return $this->maskMiddle($email, mb_strlen($email) > 10 ? 6 : 4);
    }

    protected function maskMiddle(string $value, int $hideLength): string
    {
        $length = mb_strlen($value);

        if ($length <= $hideLength + 2) {
            return mb_substr($value, 0, 1) . str_repeat('*', min($hideLength, max(1, $length - 1)));
        }

        $left = (int) floor(($length - $hideLength) / 2);
        $right = $length - $hideLength - $left;

        return mb_substr($value, 0, $left) . str_repeat('*', $hideLength) . mb_substr($value, -$right);
    }

    protected function traderValue(CopyTradingTrader $trader, string $column, $default = null)
    {
        if (!array_key_exists($column, $trader->getAttributes())) {
            return $default;
        }

        return $trader->getAttribute($column);
    }
}
