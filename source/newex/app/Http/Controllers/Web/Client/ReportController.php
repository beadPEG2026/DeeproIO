<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\Currency\CurrencyLiteCollection;
use App\Http\Resources\Launchpad\Launchpad;
use App\Http\Resources\Launchpad\LaunchpadCollection;
use App\Http\Resources\Launchpad\LaunchpadTransaction;
use App\Http\Resources\Market\MarketLiteCollection;
use App\Http\Resources\Option\Option;
use App\Http\Resources\Order\OpenFuturesOrder;
use App\Http\Resources\Order\OrderHistory;
use App\Http\Resources\ReferralTransaction\ReferralTransaction;
use App\Http\Resources\Transaction\FuturesTransaction;
use App\Http\Resources\Transaction\Trades\Trade;
use App\Http\Resources\Wallet\Deposit\Deposit;
use App\Http\Resources\Wallet\Deposit\FiatDeposit;
use App\Http\Resources\Wallet\Withdrawal\FiatWithdrawal;
use App\Http\Resources\Wallet\Withdrawal\Withdrawal;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\FundingFeeDistributionRepository;
use App\Repositories\Launchpad\LaunchpadRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Option\OptionRepository;
use App\Repositories\Order\OrderHistoryRepository;
use App\Repositories\Transaction\FuturesTransactionRepository;
use App\Repositories\Transaction\ReferralTransactionRepository;
use App\Repositories\Transaction\TransactionRepository;
use App\Repositories\Withdrawal\FiatWithdrawalRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Setting;
use App\Models\User\User;
class ReportController extends Controller
{
    public function depositBonuses() {
        $repo = new \App\Repositories\Bonus\DepositBonusRepository();
        $records = $repo->getReportForUser(auth()->user()->id);
        return Inertia::render('Report/DepositBonuses', [
            'filters' => request()->all(['search']),
            'records' => $records,
        ]);
    }

    public function adjustments() {
        $repo = new \App\Repositories\Wallet\WalletAdjustmentRepository();
        $records = $repo->getReportForUser(auth()->user()->id);
        return Inertia::render('Report/Adjustments', [
            'filters' => request()->all(['search']),
            'records' => $records,
        ]);
    }
    public function deposits() {

        $depositRepository = new DepositRepository();
        $currencyRepository = new CurrencyRepository();

        $currencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'coin'));

        $deposits = Deposit::collection($depositRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/Deposits', [
            'filters' => Request::all(['currency','txn','status']),
            'deposits' => $deposits,
            'currencies' => $currencies
        ]);
    }

public function withdrawals()
{
    $withdrawalRepository = new WithdrawalRepository();
    $currencyRepository = new CurrencyRepository();

    $currencies = new CurrencyLiteCollection(
        $currencyRepository->all(false, false, [], 'coin')
    );

    $withdrawals = Withdrawal::collection(
        $withdrawalRepository->getReportUser(auth()->user())
    )->response()->getData(true);

    return Inertia::render('Report/Withdrawals', [
        'filters' => Request::all(['currency', 'txn', 'status']),
        'withdrawals' => $withdrawals,
        'currencies' => $currencies,
    ]);
}
public function feeRefunds()
{
    $userId = auth()->id();

    $type = Request::get('type', null);
    $search = trim((string) Request::get('search', ''));

    $queries = [];

    /*
     * 现货手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('spot_fee_refund_records')) {
        $spotQuery = \Illuminate\Support\Facades\DB::table('spot_fee_refund_records')
            ->selectRaw("
                CAST(id AS TEXT) as id,
                'spot' as product_type,
                user_id,
                wallet_id,
                CAST(transaction_id AS TEXT) as source_id,
                CAST(order_id AS TEXT) as order_id,
                market_id,
                currency_id,
                CAST(fee_role AS TEXT) as fee_type,
                original_fee,
                vip_level,
                discount_rate,
                refund_rate,
                refund_amount,
                created_at,
                updated_at
            ")
            ->where('user_id', $userId);

        $queries[] = $spotQuery;
    }

    /*
     * 合约手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('futures_fee_refund_records')) {
        $hasVipLevel = \Illuminate\Support\Facades\Schema::hasColumn('futures_fee_refund_records', 'vip_level');
        $hasDiscountRate = \Illuminate\Support\Facades\Schema::hasColumn('futures_fee_refund_records', 'discount_rate');

        $vipLevelSelect = $hasVipLevel
            ? 'vip_level'
            : 'CAST(0 AS INTEGER) as vip_level';

        $discountRateSelect = $hasDiscountRate
            ? 'discount_rate'
            : 'CAST(1 AS NUMERIC) as discount_rate';

        $futuresQuery = \Illuminate\Support\Facades\DB::table('futures_fee_refund_records')
            ->selectRaw("
                CAST(id AS TEXT) as id,
                'futures' as product_type,
                user_id,
                wallet_id,
                CAST(future_contract_id AS TEXT) as source_id,
                CAST(future_contract_id AS TEXT) as order_id,
                market_id,
                currency_id,
                CAST(fee_type AS TEXT) as fee_type,
                original_fee,
                {$vipLevelSelect},
                {$discountRateSelect},
                refund_rate,
                refund_amount,
                created_at,
                updated_at
            ")
            ->where('user_id', $userId);

        $queries[] = $futuresQuery;
    }

    /*
     * 期权手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('option_fee_refund_records')) {
        $optionQuery = \Illuminate\Support\Facades\DB::table('option_fee_refund_records')
            ->selectRaw("
                CAST(id AS TEXT) as id,
                'option' as product_type,
                user_id,
                wallet_id,
                CAST(option_uuid AS TEXT) as source_id,
                CAST(option_uuid AS TEXT) as order_id,
                market_id,
                currency_id,
                'entry' as fee_type,
                original_fee,
                vip_level,
                discount_rate,
                refund_rate,
                refund_amount,
                created_at,
                updated_at
            ")
            ->where('user_id', $userId);

        $queries[] = $optionQuery;
    }

    /*
     * 转账手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('transfer_fee_refund_records')) {
        $transferQuery = \Illuminate\Support\Facades\DB::table('transfer_fee_refund_records')
            ->selectRaw("
                CAST(id AS TEXT) as id,
                'transfer' as product_type,
                user_id,
                wallet_id,
                COALESCE(CAST(commission_record_id AS TEXT), CAST(id AS TEXT)) as source_id,
                COALESCE(CAST(commission_record_id AS TEXT), CAST(id AS TEXT)) as order_id,
                CAST(NULL AS BIGINT) as market_id,
                currency_id,
                CAST(direction AS TEXT) as fee_type,
                original_fee,
                vip_level,
                discount_rate,
                refund_rate,
                refund_amount,
                created_at,
                updated_at
            ")
            ->where('user_id', $userId);

        $queries[] = $transferQuery;
    }

    /*
     * 如果返还表都不存在，返回空分页，避免页面报错
     */
    if (empty($queries)) {
        $records = new \Illuminate\Pagination\LengthAwarePaginator(
            [],
            0,
            50,
            Request::get('page', 1),
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        return Inertia::render('Report/FeeRefunds', [
            'filters' => Request::all(['type', 'search']),
            'records' => $records,
            'total_refund_amount' => '0.00000000',
            'total_records' => 0,
        ]);
    }

    /*
     * 合并所有手续费返还记录
     */
    $unionQuery = array_shift($queries);

    foreach ($queries as $query) {
        $unionQuery->unionAll($query);
    }

    $recordsQuery = \Illuminate\Support\Facades\DB::query()
        ->fromSub($unionQuery, 'fee_refunds');

    /*
     * 类型筛选：spot / futures / option / transfer
     */
    if (!empty($type) && in_array($type, ['spot', 'futures', 'option', 'transfer'])) {
        $recordsQuery->where('product_type', $type);
    }

    /*
     * 搜索：交易 ID / 订单 ID / 返还记录 ID
     */
    if ($search !== '') {
        $recordsQuery->where(function ($query) use ($search) {
            $keyword = '%' . $search . '%';

            $query->whereRaw('source_id ILIKE ?', [$keyword])
                ->orWhereRaw('order_id ILIKE ?', [$keyword]);
        });
    }

    $totalRefundAmount = (clone $recordsQuery)->sum('refund_amount');
    $totalRecords = (clone $recordsQuery)->count();

    $records = $recordsQuery
        ->orderByDesc('created_at')
        ->paginate(50)
        ->withQueryString();

    return Inertia::render('Report/FeeRefunds', [
        'filters' => Request::all(['type', 'search']),
        'records' => $records,
        'total_refund_amount' => number_format((float) $totalRefundAmount, 8, '.', ''),
        'total_records' => $totalRecords,
    ]);
}
    public function fiatDeposits() {

        $depositRepository = new FiatDepositRepository();
        $currencyRepository = new CurrencyRepository();

        $currencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'fiat'));

        $deposits = FiatDeposit::collection($depositRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/FiatDeposits', [
            'filters' => Request::all(['currency', 'status']),
            'deposits' => $deposits,
            'currencies' => $currencies
        ]);
    }

    public function fiatWithdrawals() {

        $withdrawalRepository = new FiatWithdrawalRepository();
        $currencyRepository = new CurrencyRepository();

        $currencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'fiat'));

        $withdrawals = FiatWithdrawal::collection($withdrawalRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/FiatWithdrawals', [
            'filters' => Request::all(['currency', 'status']),
            'withdrawals' => $withdrawals,
            'currencies' => $currencies
        ]);
    }

    public function trades() {

        $transactionRepository = new TransactionRepository();
        $marketRepository = new MarketRepository();

        $markets = new MarketLiteCollection($marketRepository->all(false));

        $transactions = Trade::collection($transactionRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/Trades', [
            'filters' => request()->all(['search', 'side']),
            'transactions' => $transactions,
            'markets' => $markets
        ]);
    }

    /**
     * @return \Inertia\Response
     */
    public function options() {

        $transactionRepository = new OptionRepository();
        $marketRepository = new MarketRepository();

        $markets = new MarketLiteCollection($marketRepository->all(false));

        $transactions = Option::collection($transactionRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/Options', [
            'filters' => Request::all(['market', 'side']),
            'transactions' => $transactions,
            'markets' => $markets
        ]);
    }

    /**
     * @return \Inertia\Response
     */
    public function futuresTrades() {

        $transactionRepository = new FuturesTransactionRepository();
        $marketRepository = new MarketRepository();

        $markets = new MarketLiteCollection($marketRepository->all(false));

        $transactions = FuturesTransaction::collection($transactionRepository->getReportUser(auth()->user()))->response()->getData(true);
        return Inertia::render('Report/FuturesTrades', [
            'filters' => Request::all(['market', 'type']),
            'transactions' => $transactions,
            'markets' => $markets
        ]);
    }

    /**
     * @return \Inertia\Response
     */
    public function orderHistory() {

        $orderHistoryRepository = new OrderHistoryRepository();
        $marketRepository = new MarketRepository();

        $markets = new MarketLiteCollection($marketRepository->all(false));

        $orders = OrderHistory::collection($orderHistoryRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/OrderHistories', [
            'filters' => Request::all(['market', 'side']),
            'orders' => $orders,
            'markets' => $markets
        ]);
    }

    /**
     * @return \Inertia\Response
     */
public function referralTransactions()
{
    $period = Request::get('period', []);
    $hasPeriod = !empty($period) && count($period) == 2 && !empty($period[0]) && !empty($period[1]);

    // 邮箱 / 用户筛选
    $email = Request::get('email', null);
    $emailKeyword = trim((string) $email);

    // S1 - S8 层级搜索
    $rawLevel = Request::get('level', null);
    $level = ($rawLevel !== null && $rawLevel !== '') ? (int) $rawLevel : null;

    // 点击某个下级邮箱，查看他的 1 级邀请
    $parentId = Request::get('parent_id', null);
    $parentId = !empty($parentId) ? (int) $parentId : null;

    $parentEmail = Request::get('parent_email', null);

    $transactionRepository = new ReferralTransactionRepository();

    /**
     * 当前页面交易记录，可能是分页数据
     */
    $transactionResult = $transactionRepository->getReportUser(auth()->user());

    /**
     * 当前用户 S1 - S8 团队层级映射。
     * 后续统计、筛选、搜索结果都使用同一张 user_id => level 映射，避免列表和顶部等级卡片口径不一致。
     */
    $teamLevelMap = $this->getTeamUserLevelMap(auth()->id(), 8);
    $teamUserIds = array_keys($teamLevelMap);

    /**
     * 查询用户范围
     *
     * 默认：当前用户 S1 - S8 团队
     * 点击 S1-S8：筛选当前范围内对应 VIP 等级的用户
     * 点击下级邮箱：切换为该下级的团队范围
     */
    $activeLevelMap = $teamLevelMap;
    $queryUserIds = $teamUserIds;
    $selectedParentUser = null;

    if (!empty($parentId)) {
        /**
         * 防止越权：
         * 只能查看自己团队里的某个下级的 1 级邀请。
         */
        if (array_key_exists($parentId, $teamLevelMap)) {
            $selectedParentUser = \App\Models\User\User::query()
                ->select(['id', 'email', 'vip'])
                ->where('id', $parentId)
                ->first();

            $activeLevelMap = $this->getTeamUserLevelMap($parentId, 8);
            $queryUserIds = array_keys($activeLevelMap);
        } else {
            $activeLevelMap = [];
            $queryUserIds = [];
        }
    } elseif ($level !== null && $level >= 0 && $level <= 8 && $emailKeyword !== '') {
        /**
         * 如果先筛选了某个用户，再点击 lv1 - lv8：
         * 这个用户会作为新的根节点，展示他下面对应层级的人。
         */
        $selectedParentUser = $this->resolveReferralRootUserFromFilter($emailKeyword, $teamUserIds);

        if ($selectedParentUser) {
            $parentId = (int) $selectedParentUser->id;
            $parentEmail = $selectedParentUser->email;
            $activeLevelMap = $this->getTeamUserLevelMap($parentId, 8);
            $queryUserIds = array_keys($activeLevelMap);
        } else {
            $activeLevelMap = [];
            $queryUserIds = [];
        }
    } elseif ($level !== null && $level >= 0 && $level <= 8) {
        $queryUserIds = $teamUserIds;
    }

    if ($selectedParentUser) {
        $selectedParentUserId = (int) $selectedParentUser->id;
        $selectedParentInviteLevel = $teamLevelMap[$selectedParentUserId] ?? null;
        $selectedParentAccountLevelLabel = $this->formatReferralAccountLevelLabel($selectedParentUser->vip ?? null);

        $selectedParentUser->invite_level = $selectedParentInviteLevel;
        $selectedParentUser->invite_level_label = $selectedParentInviteLevel ? 'lv' . $selectedParentInviteLevel : '-';
        $selectedParentUser->account_level = $selectedParentAccountLevelLabel !== '-' ? (int) ($selectedParentUser->vip ?? 0) : null;
        $selectedParentUser->account_level_label = $selectedParentAccountLevelLabel;
        $selectedParentUser->level = $selectedParentUser->account_level;
        $selectedParentUser->level_label = $selectedParentAccountLevelLabel;
    }

    $applyEmailFilter = empty($selectedParentUser);

    /**
     * 获取当前用户所有返佣记录，不受分页影响
     *
     * 注意：
     * referral_transactions.user_id 是拿返佣的人
     * referral_transactions.transaction_id 才能找到真实产生交易的人
     */
    $commissionRowsQuery = \Illuminate\Support\Facades\DB::table('referral_transactions')
        ->select(['id', 'user_id', 'transaction_id', 'amount', 'created_at', 'balance_domain', 'is_credited', 'currency_id'])
        ->where('user_id', auth()->id());

    if ($hasPeriod) {
        $commissionRowsQuery->whereBetween('created_at', [
            $period[0] . ' 00:00:00',
            $period[1] . ' 23:59:59',
        ]);
    }

    $commissionRows = $commissionRowsQuery->get();

    /**
     * 当日返佣记录：
     * 永远统计今天，不受 period 影响。
     */
    $todayStart = now()->startOfDay()->format('Y-m-d H:i:s');
    $todayEnd = now()->endOfDay()->format('Y-m-d H:i:s');

    $todayCommissionRows = \Illuminate\Support\Facades\DB::table('referral_transactions')
        ->select(['id', 'user_id', 'transaction_id', 'amount', 'created_at', 'balance_domain', 'is_credited', 'currency_id'])
        ->where('user_id', auth()->id())
        ->whereBetween('created_at', [$todayStart, $todayEnd])
        ->get();

    /**
     * 映射需要覆盖累计返佣和当日返佣两部分记录。
     */
    $mappingCommissionRows = collect($commissionRows)
        ->merge($todayCommissionRows)
        ->unique('id')
        ->values();

    /**
     * 拆分所有返佣记录里的现货 / 合约 transaction_id
     */
    $allSpotTransactionIds = [];
    $allFuturesTransactionIds = [];

    foreach ($mappingCommissionRows as $row) {
        $rawTransactionId = (string) ($row->transaction_id ?? '');

        if ($rawTransactionId === '') {
            continue;
        }

        // 纯数字，现货
        if (ctype_digit($rawTransactionId)) {
            $allSpotTransactionIds[] = $rawTransactionId;
            continue;
        }

        // UUID，合约
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $rawTransactionId)) {
            $allFuturesTransactionIds[] = $rawTransactionId;
            continue;
        }
    }

    $allSpotTransactionIds = array_values(array_unique($allSpotTransactionIds));
    $allFuturesTransactionIds = array_values(array_unique($allFuturesTransactionIds));

    /**
     * transaction_id => real_user_id
     */
    $transactionUserIdMap = [];

    /**
     * transaction_id => transaction_type
     */
    $transactionTypeMap = [];

    /**
     * 查询现货订单
     *
     * 你现在现货表使用 transactions.id
     */
    if (!empty($allSpotTransactionIds)) {
        $spotTables = [
            'transactions',
        ];

        $spotIdColumns = [
            'id',
        ];

        foreach ($spotTables as $spotTable) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($spotTable)) {
                continue;
            }

            if (!\Illuminate\Support\Facades\Schema::hasColumn($spotTable, 'user_id')) {
                continue;
            }

            foreach ($spotIdColumns as $idColumn) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn($spotTable, $idColumn)) {
                    continue;
                }

                $spotOrders = \Illuminate\Support\Facades\DB::table($spotTable)
                    ->select([$idColumn, 'user_id'])
                    ->whereIn($idColumn, $allSpotTransactionIds)
                    ->get();

                foreach ($spotOrders as $spotOrder) {
                    $key = (string) $spotOrder->{$idColumn};

                    if ($key === '') {
                        continue;
                    }

                    $transactionUserIdMap[$key] = $spotOrder->user_id;
                    $transactionTypeMap[$key] = 'spot';
                }
            }
        }
    }

    /**
     * 查询合约订单
     *
     * 你现在合约表使用 futures_contract.id
     */
    if (!empty($allFuturesTransactionIds)) {
        $futuresTables = [
            'futures_contract',
        ];

        $futuresIdColumns = [
            'id',
        ];

        foreach ($futuresTables as $futuresTable) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($futuresTable)) {
                continue;
            }

            if (!\Illuminate\Support\Facades\Schema::hasColumn($futuresTable, 'user_id')) {
                continue;
            }

            foreach ($futuresIdColumns as $idColumn) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn($futuresTable, $idColumn)) {
                    continue;
                }

                $futuresOrders = \Illuminate\Support\Facades\DB::table($futuresTable)
                    ->select([$idColumn, 'user_id'])
                    ->whereIn($idColumn, $allFuturesTransactionIds)
                    ->get();

                foreach ($futuresOrders as $futuresOrder) {
                    $key = (string) $futuresOrder->{$idColumn};

                    if ($key === '') {
                        continue;
                    }

                    $transactionUserIdMap[$key] = $futuresOrder->user_id;
                    $transactionTypeMap[$key] = 'futures';
                }
            }
        }
    }

    $eventKeys = DB::table('referral_transactions')->where('user_id',auth()->id())->whereNotNull('event_key')->pluck('event_key');
    $eventRows = DB::table('exchange_referral_events')->whereIn('id',$eventKeys)->get();
    $rowEvents = DB::table('referral_transactions')->where('user_id',auth()->id())->whereNotNull('event_key')->get(['event_key','transaction_id']);
    $eventsByKey=$eventRows->keyBy('id');
    foreach($rowEvents as $referralRow) if($event=$eventsByKey->get($referralRow->event_key)) {
        $transactionUserIdMap[(string)$referralRow->transaction_id]=$event->source_user_id;
        $transactionTypeMap[(string)$referralRow->transaction_id]=$event->business==='spot'?'spot':'futures';
    }

    /**
     * 查真实产生交易用户的邮箱和账号等级
     */
    $realUserIds = collect($transactionUserIdMap)
        ->values()
        ->filter()
        ->unique()
        ->values()
        ->toArray();

    $userEmailMap = [];
    $userAccountLevelLabelMap = [];

    if (!empty($realUserIds)) {
        $realUsers = \App\Models\User\User::query()
            ->select(['id', 'email', 'vip'])
            ->whereIn('id', $realUserIds)
            ->get();

        foreach ($realUsers as $realUser) {
            $userEmailMap[$realUser->id] = $realUser->email;
            $userAccountLevelLabelMap[$realUser->id] = $this->formatReferralAccountLevelLabel($realUser->vip ?? null);
        }
    }

    /**
     * real_user_id => accumulated_commission
     * real_user_id => today_commission
     *
     * referral_transactions.user_id 是拿返佣的人。
     * real_user_id 是真实产生交易的人。
     */
    $userCommissionAmountMap = [];
    $todayCommissionAmountMap = [];

    /**
     * 累计返佣：
     * 跟随当前选择的 period。
     */
    $usdtCurrencyId = (int)\App\Models\Currency\Currency::where('symbol','USDT')->value('id');
    foreach ($commissionRows as $row) {
        if (!$row->is_credited || $row->balance_domain!=='real' || (int)$row->currency_id !== $usdtCurrencyId) continue;
        $rawTransactionId = (string) ($row->transaction_id ?? '');

        if ($rawTransactionId === '') {
            continue;
        }

        $realUserId = $transactionUserIdMap[$rawTransactionId] ?? null;

        if (!$realUserId) {
            continue;
        }

        $amount = (float) ($row->amount ?? 0);

        if (!isset($userCommissionAmountMap[$realUserId])) {
            $userCommissionAmountMap[$realUserId] = 0;
        }

        $userCommissionAmountMap[$realUserId] += $amount;
    }

    /**
     * 当日返佣：
     * 始终统计今天，不受 period 影响。
     */
    foreach ($todayCommissionRows as $row) {
        if (!$row->is_credited || $row->balance_domain!=='real' || (int)$row->currency_id !== $usdtCurrencyId) continue;
        $rawTransactionId = (string) ($row->transaction_id ?? '');

        if ($rawTransactionId === '') {
            continue;
        }

        $realUserId = $transactionUserIdMap[$rawTransactionId] ?? null;

        if (!$realUserId) {
            continue;
        }

        $amount = (float) ($row->amount ?? 0);

        if (!isset($todayCommissionAmountMap[$realUserId])) {
            $todayCommissionAmountMap[$realUserId] = 0;
        }

        $todayCommissionAmountMap[$realUserId] += $amount;
    }

    /**
     * 页面交易记录日期筛选
     */
    if ($hasPeriod) {
        $start = $period[0] . ' 00:00:00';
        $end = $period[1] . ' 23:59:59';

        if (
            $transactionResult instanceof \Illuminate\Pagination\LengthAwarePaginator ||
            $transactionResult instanceof \Illuminate\Pagination\Paginator
        ) {
            $filteredCollection = $transactionResult->getCollection()->filter(function ($item) use ($start, $end) {
                $createdAt = $item->created_at instanceof \Carbon\Carbon
                    ? $item->created_at->format('Y-m-d H:i:s')
                    : (string) $item->created_at;

                return $createdAt >= $start && $createdAt <= $end;
            })->values();

            $transactionResult->setCollection($filteredCollection);
        } else {
            $transactionResult = $transactionResult->filter(function ($item) use ($start, $end) {
                $createdAt = $item->created_at instanceof \Carbon\Carbon
                    ? $item->created_at->format('Y-m-d H:i:s')
                    : (string) $item->created_at;

                return $createdAt >= $start && $createdAt <= $end;
            })->values();
        }
    }

    /**
     * 当前页面返佣交易集合
     */
    if (
        $transactionResult instanceof \Illuminate\Pagination\LengthAwarePaginator ||
        $transactionResult instanceof \Illuminate\Pagination\Paginator
    ) {
        $transactionCollection = $transactionResult->getCollection();
    } else {
        $transactionCollection = collect($transactionResult);
    }

    /**
     * referral_transaction_id => source_transaction_id
     * referral_transaction_id => email
     * referral_transaction_id => transaction_type
     * referral_transaction_id => level
     */
    $referralTransactionRawIdMap = [];
    $referralTransactionEmailMap = [];
    $referralTransactionTypeMap = [];
    $referralTransactionLevelMap = [];

    foreach ($transactionCollection as $item) {
        $rawTransactionId = (string) ($item->transaction_id ?? '');

        $referralTransactionRawIdMap[$item->id] = $rawTransactionId;

        $realUserId = $rawTransactionId !== '' && isset($transactionUserIdMap[$rawTransactionId])
            ? $transactionUserIdMap[$rawTransactionId]
            : null;

        $referralTransactionEmailMap[$item->id] = $realUserId && isset($userEmailMap[$realUserId])
            ? $userEmailMap[$realUserId]
            : '-';

        $referralTransactionLevelMap[$item->id] = $realUserId && isset($userAccountLevelLabelMap[(int) $realUserId])
            ? $userAccountLevelLabelMap[(int) $realUserId]
            : null;

        if ($rawTransactionId !== '' && isset($transactionTypeMap[$rawTransactionId])) {
            $referralTransactionTypeMap[$item->id] = $transactionTypeMap[$rawTransactionId];
        } elseif ($rawTransactionId !== '' && ctype_digit($rawTransactionId)) {
            $referralTransactionTypeMap[$item->id] = 'spot';
        } elseif ($rawTransactionId !== '' && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $rawTransactionId)) {
            $referralTransactionTypeMap[$item->id] = 'futures';
        } else {
            $referralTransactionTypeMap[$item->id] = '-';
        }
    }

    /**
     * 原本的 Resource 转换
     */
    $transactions = ReferralTransaction::collection($transactionResult)->response()->getData(true);

    /**
     * 给前端 transactions.data 注入：
     * email
     * transaction_type
     * source_transaction_id
     */
    if (!empty($transactions['data'])) {
        foreach ($transactions['data'] as $key => $transaction) {
            $referralTransactionId = $transaction['id'] ?? null;

            $transactions['data'][$key]['email'] = $referralTransactionId && isset($referralTransactionEmailMap[$referralTransactionId])
                ? $referralTransactionEmailMap[$referralTransactionId]
                : '-';

            $transactions['data'][$key]['transaction_type'] = $referralTransactionId && isset($referralTransactionTypeMap[$referralTransactionId])
                ? $referralTransactionTypeMap[$referralTransactionId]
                : '-';

            $transactions['data'][$key]['source_transaction_id'] = $referralTransactionId && isset($referralTransactionRawIdMap[$referralTransactionId])
                ? $referralTransactionRawIdMap[$referralTransactionId]
                : '-';

            $transactionLevelLabel = $referralTransactionId && isset($referralTransactionLevelMap[$referralTransactionId])
                ? $referralTransactionLevelMap[$referralTransactionId]
                : '-';

            $transactions['data'][$key]['level'] = $transactionLevelLabel !== '-'
                ? (int) str_replace('lv', '', $transactionLevelLabel)
                : null;
            $transactions['data'][$key]['level_label'] = $transactionLevelLabel;
        }
    }

    /**
     * 推荐用户列表
     *
     * 这里不能再用 referral_transactions.user_id = users.id 算累计返佣。
     * 因为 referral_transactions.user_id 是拿返佣的人。
     */
    $referredUsersQuery = \App\Models\User\User::query()
        ->select(['id', 'name', 'email', 'created_at', 'vip'])
        ->whereIn('id', $queryUserIds)
        ->orderByDesc('id');

    /**
     * 日期筛选，保留你原来的逻辑：
     * 推荐用户注册时间在所选日期内。
     */
    if ($hasPeriod) {
        $referredUsersQuery->whereBetween('created_at', [
            $period[0] . ' 00:00:00',
            $period[1] . ' 23:59:59',
        ]);
    }

    /**
     * Email 搜索
     */
    if ($applyEmailFilter && $emailKeyword !== '') {
        $referredUsersQuery->where('email', 'like', '%' . $emailKeyword . '%');
    }

    if ($level !== null && $level >= 0 && $level <= 8) {
        if ((int) $level === 0) {
            $referredUsersQuery->where(function ($query) {
                $query->where('vip', 0)->orWhereNull('vip');
            });
        } else {
            $referredUsersQuery->where('vip', (int) $level);
        }
    }

    /**
     * 用于统计顶部总返佣金额的用户范围
     */
    $summaryUserIds = (clone $referredUsersQuery)
        ->pluck('id')
        ->map(function ($id) {
            return (int) $id;
        })
        ->toArray();

    /**
     * 顶部总返佣金额
     *
     * 会跟随：
     * 1. S1 - S8
     * 2. Email 搜索
     * 3. 日期筛选
     * 4. parent_id 查看某个下级的 1 级邀请
     */
    $referralAmount = collect($userCommissionAmountMap)
        ->filter(function ($amount, $userId) use ($summaryUserIds) {
            return in_array((int) $userId, $summaryUserIds);
        })
        ->sum();

    /**
     * 顶部当日返佣金额
     *
     * 会跟随：
     * 1. S1 - S8
     * 2. Email 搜索
     * 3. parent_id 查看某个下级的 1 级邀请
     *
     * 当日返佣本身永远统计今天，不受 period 影响。
     */
    $todayReferralAmount = collect($todayCommissionAmountMap)
        ->filter(function ($amount, $userId) use ($summaryUserIds) {
            return in_array((int) $userId, $summaryUserIds);
        })
        ->sum();

    /**
     * 推荐用户分页
     */
    $referredUsers = $referredUsersQuery
        ->paginate(25)
        ->appends(Request::all())
        ->toArray();

    $visibleReferredUserIds = collect($referredUsers['data'] ?? [])
        ->pluck('id')
        ->map(function ($id) {
            return (int) $id;
        })
        ->filter()
        ->values()
        ->toArray();

    $directInviteCountMap = [];

    if (!empty($visibleReferredUserIds)) {
        $directInviteCountMap = \App\Models\User\User::query()
            ->selectRaw('referral_id, COUNT(*) as total')
            ->whereIn('referral_id', $visibleReferredUserIds)
            ->groupBy('referral_id')
            ->pluck('total', 'referral_id')
            ->map(function ($count) {
                return (int) $count;
            })
            ->toArray();
    }

    /**
     * 给推荐用户列表注入：
     * invite_level / invite_level_label 当前筛选口径下的邀请层级
     * level / level_label 账号本身等级，和后台用户列表里的 VIP 等级一致
     * accumulated_commission 累计佣金
     * today_commission 当日佣金
     */
    if (!empty($referredUsers['data'])) {
        foreach ($referredUsers['data'] as $key => $user) {
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            $userInviteLevel = $userId && isset($activeLevelMap[$userId])
                ? (int) $activeLevelMap[$userId]
                : null;
            $accountLevelLabel = $this->formatReferralAccountLevelLabel($user['vip'] ?? null);

            $referredUsers['data'][$key]['invite_level'] = $userInviteLevel;
            $referredUsers['data'][$key]['invite_level_label'] = $userInviteLevel ? 'lv' . $userInviteLevel : '-';
            $referredUsers['data'][$key]['account_level'] = $accountLevelLabel !== '-'
                ? (int) str_replace('lv', '', $accountLevelLabel)
                : null;
            $referredUsers['data'][$key]['account_level_label'] = $accountLevelLabel;
            $referredUsers['data'][$key]['level'] = $referredUsers['data'][$key]['account_level'];
            $referredUsers['data'][$key]['level_label'] = $accountLevelLabel;

            $referredUsers['data'][$key]['accumulated_commission'] = $userId && isset($userCommissionAmountMap[$userId])
                ? number_format((float) $userCommissionAmountMap[$userId], 4, '.', '')
                : '0.0000';

            $referredUsers['data'][$key]['today_commission'] = $userId && isset($todayCommissionAmountMap[$userId])
                ? number_format((float) $todayCommissionAmountMap[$userId], 4, '.', '')
                : '0.0000';

            $referredUsers['data'][$key]['direct_invite_count'] = $userId && isset($directInviteCountMap[$userId])
                ? (int) $directInviteCountMap[$userId]
                : 0;
        }
    }

    /**
     * 总推荐用户数量，也跟随 S1 - S8 / 日期 / 邮箱筛选 / parent_id。
     * 默认不包含当前登录用户本人，也不包含 8 级之外的用户。
     */
    $totalReferredUsersQuery = \App\Models\User\User::query()
        ->whereIn('id', $queryUserIds);

    if ($hasPeriod) {
        $totalReferredUsersQuery->whereBetween('created_at', [
            $period[0] . ' 00:00:00',
            $period[1] . ' 23:59:59',
        ]);
    }

    if ($applyEmailFilter && $emailKeyword !== '') {
        $totalReferredUsersQuery->where('email', 'like', '%' . $emailKeyword . '%');
    }

    if ($level !== null && $level >= 0 && $level <= 8) {
        if ((int) $level === 0) {
            $totalReferredUsersQuery->where(function ($query) {
                $query->where('vip', 0)->orWhereNull('vip');
            });
        } else {
            $totalReferredUsersQuery->where('vip', (int) $level);
        }
    }

    $totalReferredUsers = $totalReferredUsersQuery->count();

    $todayReferredUsersQuery = \App\Models\User\User::query()
        ->whereIn('id', $queryUserIds)
        ->whereBetween('created_at', [
            now()->startOfDay()->format('Y-m-d H:i:s'),
            now()->endOfDay()->format('Y-m-d H:i:s'),
        ]);

    if ($applyEmailFilter && $emailKeyword !== '') {
        $todayReferredUsersQuery->where('email', 'like', '%' . $emailKeyword . '%');
    }

    if ($level !== null && $level >= 0 && $level <= 8) {
        if ((int) $level === 0) {
            $todayReferredUsersQuery->where(function ($query) {
                $query->where('vip', 0)->orWhereNull('vip');
            });
        } else {
            $todayReferredUsersQuery->where('vip', (int) $level);
        }
    }

    $todayReferredUsers = $todayReferredUsersQuery->count();

    /**
     * VIP lv0 - lv8 数量统计
     *
     * 如果正在查看某个下级，则统计这个下级团队里的 VIP 等级分布。
     * 否则统计当前用户团队里的 VIP 等级分布。
     */
    $levelCounts = [];

    $levelRootMap = $selectedParentUser
        ? $activeLevelMap
        : $teamLevelMap;

    for ($i = 0; $i <= 8; $i++) {
        $ids = array_keys($levelRootMap);

        $countQuery = \App\Models\User\User::query()
            ->whereIn('id', $ids);

        if ($i === 0) {
            $countQuery->where(function ($query) {
                $query->where('vip', 0)->orWhereNull('vip');
            });
        } else {
            $countQuery->where('vip', $i);
        }

        if ($hasPeriod) {
            $countQuery->whereBetween('created_at', [
                $period[0] . ' 00:00:00',
                $period[1] . ' 23:59:59',
            ]);
        }

        if ($applyEmailFilter && $emailKeyword !== '') {
            $countQuery->where('email', 'like', '%' . $emailKeyword . '%');
        }

        $levelCounts['s' . $i] = $countQuery->count();
    }

    $filters = Request::all(['search', 'period', 'email', 'level', 'parent_id', 'parent_email']);

    if ($selectedParentUser) {
        $filters['parent_id'] = (int) $selectedParentUser->id;
        $filters['parent_email'] = $selectedParentUser->email;
    }

    return Inertia::render('Report/ReferralTransactions', [
        'filters' => $filters,
        'transactions' => $transactions,
        'referred_users' => $referredUsers,
        'total_referred_users' => $totalReferredUsers,
        'today_referred_users' => $todayReferredUsers,
        'referral_amount' => number_format((float) $referralAmount, 4, '.', ''),
        'today_referral_amount' => number_format((float) $todayReferralAmount, 4, '.', ''),
        'current_date' => now()->toDateString(),
        'level_counts' => $levelCounts,
        'selected_parent_user' => $selectedParentUser,
    ]);
}
protected function getTeamUserLevelMap(int $userId, int $maxLevel = 8): array
{
    if ($userId <= 0 || $maxLevel < 1) {
        return [];
    }

    $levelMap = [];
    $visitedIds = [$userId];
    $currentLevelIds = [$userId];

    for ($level = 1; $level <= $maxLevel; $level++) {
        $children = User::query()
            ->whereIn('referral_id', $currentLevelIds)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter(function ($id) {
                return $id > 0;
            })
            ->values()
            ->toArray();

        $children = array_values(array_diff($children, $visitedIds));

        if (empty($children)) {
            break;
        }

        foreach ($children as $childId) {
            if (!isset($levelMap[$childId])) {
                $levelMap[$childId] = $level;
            }
        }

        $visitedIds = array_values(array_unique(array_merge($visitedIds, $children)));
        $currentLevelIds = $children;
    }

    return $levelMap;
}

protected function formatReferralAccountLevelLabel($level): string
{
    if (is_string($level)) {
        $level = trim($level);
    }

    if ($level === null || $level === '') {
        return 'lv0';
    }

    if (is_numeric($level)) {
        $level = (int) $level;
    } else {
        if (!preg_match('/\d+/', (string) $level, $matches)) {
            return '-';
        }

        $level = (int) $matches[0];
    }

    return $level >= 0 && $level <= 8 ? 'lv' . $level : '-';
}

protected function resolveReferralRootUserFromFilter(?string $keyword, array $teamUserIds): ?User
{
    $keyword = trim((string) $keyword);
    $teamUserIds = array_values(array_unique(array_map('intval', $teamUserIds)));

    if ($keyword === '' || empty($teamUserIds)) {
        return null;
    }

    $exactUserQuery = User::query()
        ->select(['id', 'name', 'email', 'referral_code', 'vip'])
        ->whereIn('id', $teamUserIds)
        ->where(function ($query) use ($keyword) {
            $query->where('email', $keyword)
                ->orWhere('referral_code', $keyword);

            if (ctype_digit($keyword)) {
                $query->orWhere('id', (int) $keyword);
            }
        });

    $exactUser = $exactUserQuery->first();

    if ($exactUser) {
        return $exactUser;
    }

    return User::query()
        ->select(['id', 'name', 'email', 'referral_code', 'vip'])
        ->whereIn('id', $teamUserIds)
        ->where(function ($query) use ($keyword) {
            $query->where('email', 'like', '%' . $keyword . '%')
                ->orWhere('referral_code', 'like', '%' . $keyword . '%')
                ->orWhere('name', 'like', '%' . $keyword . '%');
        })
        ->orderByDesc('id')
        ->first();
}

protected function getLevelTeamUserIds(int $userId, int $level): array
{
    if ($level < 1) {
        return [];
    }

    $currentLevelIds = [$userId];

    for ($i = 1; $i <= $level; $i++) {
        $currentLevelIds = \App\Models\User\User::query()
            ->whereIn('referral_id', $currentLevelIds)
            ->pluck('id')
            ->toArray();

        if (empty($currentLevelIds)) {
            return [];
        }
    }

    return array_values(array_unique($currentLevelIds));
}
protected function getAllTeamUserIds(int $userId): array
    {
        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = User::query()
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return array_unique($allIds);
    }
    /**
     * @return \Inertia\Response
     */
    public function launchpadTransactions() {

        $transactionRepository = new LaunchpadRepository();

        $transactions = LaunchpadTransaction::collection($transactionRepository->getReportUser(auth()->user()))->response()->getData(true);

        return Inertia::render('Report/LaunchpadTransactions', [
            'filters' => Request::all(['search']),
            'transactions' => $transactions,
        ]);
    }

    public function fundingFeeDistributions() {
        $repository = new FundingFeeDistributionRepository();
        $marketRepository = new MarketRepository();
        
        $markets = new MarketLiteCollection($marketRepository->all(false));
        $distributions = $repository->getReportForUser(auth()->user());

        return Inertia::render('Report/FundingFeeDistributions', [
            'filters' => Request::all(['market', 'start_date', 'end_date']),
            'distributions' => $distributions,
            'markets' => $markets,
        ]);
    }
}
