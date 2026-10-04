<?php

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Jetstream\DeleteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\User\UserFormRequest;
use App\Models\Market\Market;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\User\UserRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * @var UserRepository
     */
    protected $userRepository;

    protected array $schemaTableCache = [];

    protected array $schemaColumnCache = [];

    protected array $currencySymbolCache = [];

    protected array $currencyRateCache = [];

    protected array $stakingProductCurrencyIdCache = [];

    protected array $virtualUserCache = [];

    protected ?array $quoteCurrencyIdsCache = null;

    /**
     * UserController Constructor
     *
     * @param UserRepository $userRepository
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function index()
    {
        $users = $this->userRepository->get();
        $pageUsers = $users->getCollection();
        $pageUserIds = $pageUsers
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter(function ($id) {
                return $id > 0;
            })
            ->unique()
            ->values()
            ->all();

        $virtualUserIds = $this->getVirtualUserIdsForList($pageUserIds);
        $balanceSummaries = $this->buildUserBalanceSummariesForList($pageUserIds, $virtualUserIds);
        $financeSummaries = $this->buildUserListFinanceSummariesForPage($pageUserIds, $virtualUserIds, $balanceSummaries);
        $usersWithChildren = $this->getUsersWithTeamChildren($pageUserIds);
        $referrerUserCache = $this->prefetchReferrerUsersForCollection($pageUsers);

        $users->getCollection()->transform(function ($user) use (
            &$referrerUserCache,
            $balanceSummaries,
            $financeSummaries,
            $usersWithChildren
        ) {
            $user->loadMissing('roles', 'referral');

            foreach (($balanceSummaries[(int) $user->id] ?? $this->zeroUserBalanceSummary()) as $key => $value) {
                $user->{$key} = $value;
            }

            foreach (($financeSummaries[(int) $user->id] ?? $this->zeroUserListFinanceSummary()) as $key => $value) {
                $user->{$key} = $value;
            }

            $user->ip_location = $this->resolveIpLocationForUserList($user);
            $user->nickname = trim((string) ($user->nickname ?? ''));
            $user->leader_display_name = $this->resolveLeaderDisplayNameForListFromCache($user, $referrerUserCache);
            $user->referrer_chain = $this->buildUserReferrerChain($user, $referrerUserCache);
            $user->referrer_display_name = $this->resolveUserReferrerDisplayName($user->referrer_chain);

            $user->has_children = isset($usersWithChildren[(int) $user->id]);
            $user->children_loaded = false;
            $user->children = [];
            $user->team_children = [];

            return $user;
        });

        $teamUserId = request()->get('team_user_id');

        $totalDepositAmount = (new \App\Repositories\Deposit\DepositRepository())->getTotalDepositAmount($teamUserId);
        $totalWithdrawalAmount = (new \App\Repositories\Withdrawal\WithdrawalRepository())->getTotalWithdrawalAmount($teamUserId);
        $onlineUserCount = $this->userRepository->countOnline($teamUserId);

        /*
         * 用户页顶部资金统计只统计真实账户。
         * 不再统计 balance_in_virtual_wallet / balance_in_virtual_trade。
         */
        $realBalances = $this->getAllUserBalances('real');

        $currentUser = auth()->user();
        $roles = $currentUser ? $currentUser->roles->pluck('name')->toArray() : [];
        $is_yw = in_array('salesman', $roles, true) ? 1 : 0;

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => request()->all([
                'search',
                'period',
                'team_user_id',
                'role',
                'status',
                'kyc_status',
                'email_status',
                'login_ip',
                'ip_location',
                'dashboard_participants',
                'duplicate_ip',
                'duplicate_account',
            ]),
            'is_yw' => $is_yw,
            'stats' => [
                'totalDepositAmount' => $totalDepositAmount,
                'totalWithdrawalAmount' => $totalWithdrawalAmount,
                'netDepositAmount' => math_formatter(
                    math_sub($totalDepositAmount, $totalWithdrawalAmount),
                    2
                ),
                /*
                 * totalUsdBalance / totalRealUsdBalance 都只返回真实账户余额。
                 * 前端不再显示 totalVirtualUsdBalance。
                 */
                'totalUsdBalance' => $realBalances['totalUsdBalance'] ?? '0.00',
                'totalRealUsdBalance' => $realBalances['totalUsdBalance'] ?? '0.00',
                'onlineUserCount' => $onlineUserCount,
                'totalFeeAmount' => (new \App\Repositories\Transaction\TransactionRepository())->getTotalFeeAmount($teamUserId),
                'totalReferralAmount' => (new \App\Repositories\Transaction\ReferralTransactionRepository())->getTotalReferralAmount($teamUserId),
                'totalfuturesFeeAmount' => (new \App\Repositories\Transaction\FuturesTransactionRepository())->getTotalFuturesFeeAmount($teamUserId),
            ],
        ]);
    }
protected function appendUserListFinanceSummaryToUser($user)
{
    $userId = (int) $user->id;

    /*
     * 用户列表金额统一口径：
     * 1. 入金 / 出金查询全部币种。
     * 2. 金额按 markets.last 折算成 USDT。
     * 3. 虚拟账户用户 is_xn = true 不参与金额统计。
     */
    $totalDeposit = $this->sumUserTableAmount('deposits', $userId);
    $totalWithdrawal = $this->sumUserTableAmount('withdrawals', $userId);

    $walletSummary = $this->getUserWalletBalanceTradeForTeamPage($userId);
    $realEarnBalance = $this->getUserRealAutoInvestBalanceForList($userId);
    $realStakingBalance = $this->getUserRealStakingBalanceForList($userId);

    $realWallet = $walletSummary['wallet_balance'] ?? 0;
    $realTrade = $walletSummary['trade_balance'] ?? 0;

    $user->list_total_deposit = $this->formatTeamMoney($totalDeposit);
    $user->list_total_withdrawal = $this->formatTeamMoney($totalWithdrawal);

    $user->list_real_wallet_balance = $this->formatTeamMoney($realWallet);
    $user->list_real_trade_balance = $this->formatTeamMoney($realTrade);
    $user->list_real_earn_balance = $this->formatTeamMoney($realEarnBalance);
    $user->list_real_staking_balance = $this->formatTeamMoney($realStakingBalance);
    $user->list_real_total_balance = $this->formatTeamMoney(
        math_sum(
            math_sum($realWallet, $realTrade),
            math_sum($realEarnBalance, $realStakingBalance)
        )
    );

    /*
     * 当前页面不显示虚拟账户资金，保留字段但统一返回 0，避免前端旧字段取值异常。
     */
    $user->list_virtual_wallet_balance = $this->formatTeamMoney(0);
    $user->list_virtual_trade_balance = $this->formatTeamMoney(0);
    $user->list_virtual_total_balance = $this->formatTeamMoney(0);

    $user->list_wallet_balance = $this->formatTeamMoney($realWallet);
    $user->list_trade_balance = $this->formatTeamMoney($realTrade);

    return $user;
}

    protected function normalizeIdList(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(function ($id) {
            return (int) $id;
        }, $ids), function ($id) {
            return $id > 0;
        })));
    }

    protected function schemaHasTableCached(string $table): bool
    {
        if (!array_key_exists($table, $this->schemaTableCache)) {
            $this->schemaTableCache[$table] = Schema::hasTable($table);
        }

        return $this->schemaTableCache[$table];
    }

    protected function schemaHasColumnCached(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, $this->schemaColumnCache)) {
            $this->schemaColumnCache[$key] = Schema::hasColumn($table, $column);
        }

        return $this->schemaColumnCache[$key];
    }

    protected function getCurrencySymbolCached(int $currencyId): ?string
    {
        if ($currencyId <= 0 || !$this->schemaHasTableCached('currencies')) {
            return null;
        }

        if (!array_key_exists($currencyId, $this->currencySymbolCache)) {
            $this->currencySymbolCache[$currencyId] = DB::table('currencies')
                ->where('id', $currencyId)
                ->value('symbol');
        }

        return $this->currencySymbolCache[$currencyId];
    }

    protected function getVirtualUserIdsForList(array $userIds): array
    {
        $userIds = $this->normalizeIdList($userIds);

        if (empty($userIds) || !$this->schemaHasTableCached('users') || !$this->schemaHasColumnCached('users', 'is_xn')) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', $userIds)
            ->where('is_xn', true)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->values()
            ->all();
    }

    protected function zeroUserListFinanceSummary(): array
    {
        $zero = $this->formatTeamMoney(0);

        return [
            'list_total_deposit' => $zero,
            'list_total_withdrawal' => $zero,
            'list_real_wallet_balance' => $zero,
            'list_real_trade_balance' => $zero,
            'list_real_earn_balance' => $zero,
            'list_real_staking_balance' => $zero,
            'list_real_total_balance' => $zero,
            'list_virtual_wallet_balance' => $zero,
            'list_virtual_trade_balance' => $zero,
            'list_virtual_total_balance' => $zero,
            'list_wallet_balance' => $zero,
            'list_trade_balance' => $zero,
        ];
    }

    protected function buildUserListFinanceSummariesForPage(array $userIds, array $virtualUserIds = [], array $balanceSummaries = []): array
    {
        $userIds = $this->normalizeIdList($userIds);
        $virtualLookup = array_fill_keys($this->normalizeIdList($virtualUserIds), true);
        $realUserIds = array_values(array_filter($userIds, function ($userId) use ($virtualLookup) {
            return !isset($virtualLookup[$userId]);
        }));

        $summaries = [];

        foreach ($userIds as $userId) {
            $summaries[$userId] = $this->zeroUserListFinanceSummary();
        }

        if (empty($realUserIds)) {
            return $summaries;
        }

        $depositTotals = $this->sumUserTableAmountsForUsers('deposits', $realUserIds);
        $withdrawalTotals = $this->sumUserTableAmountsForUsers('withdrawals', $realUserIds);
        $autoInvestTotals = $this->sumAutoInvestBalancesForUsers($realUserIds);
        $stakingTotals = $this->sumStakingBalancesForUsers($realUserIds);

        foreach ($realUserIds as $userId) {
            $balance = $balanceSummaries[$userId] ?? $this->zeroUserBalanceSummary();
            $realWallet = $this->cleanMoneyNumber($balance['real_wallet_balance'] ?? 0);
            $realTrade = $this->cleanMoneyNumber($balance['real_trade_balance'] ?? 0);
            $realEarnBalance = $autoInvestTotals[$userId] ?? '0';
            $realStakingBalance = $stakingTotals[$userId] ?? '0';

            $summaries[$userId] = [
                'list_total_deposit' => $this->formatTeamMoney($depositTotals[$userId] ?? 0),
                'list_total_withdrawal' => $this->formatTeamMoney($withdrawalTotals[$userId] ?? 0),
                'list_real_wallet_balance' => $this->formatTeamMoney($realWallet),
                'list_real_trade_balance' => $this->formatTeamMoney($realTrade),
                'list_real_earn_balance' => $this->formatTeamMoney($realEarnBalance),
                'list_real_staking_balance' => $this->formatTeamMoney($realStakingBalance),
                'list_real_total_balance' => $this->formatTeamMoney(
                    math_sum(
                        math_sum($realWallet, $realTrade),
                        math_sum($realEarnBalance, $realStakingBalance)
                    )
                ),
                'list_virtual_wallet_balance' => $this->formatTeamMoney(0),
                'list_virtual_trade_balance' => $this->formatTeamMoney(0),
                'list_virtual_total_balance' => $this->formatTeamMoney(0),
                'list_wallet_balance' => $this->formatTeamMoney($realWallet),
                'list_trade_balance' => $this->formatTeamMoney($realTrade),
            ];
        }

        return $summaries;
    }

    protected function sumUserTableAmountsForUsers(string $table, array $userIds): array
    {
        $userIds = $this->normalizeIdList($userIds);
        $totals = [];

        foreach ($userIds as $userId) {
            $totals[$userId] = '0';
        }

        if (
            empty($userIds) ||
            !$this->schemaHasTableCached($table) ||
            !$this->schemaHasColumnCached($table, 'user_id')
        ) {
            return $totals;
        }

        $amountColumn = $this->resolveAmountColumn($table);

        if (!$amountColumn) {
            return $totals;
        }

        $query = DB::table($table)->whereIn('user_id', $userIds);

        $this->applyInternalTransferDepositScope($table, $query);

        if ($this->schemaHasColumnCached($table, 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', [
                        'rejected',
                        'failed',
                        'cancelled',
                        'canceled',
                        'error',
                        'pending',
                    ]);
            });
        }

        if ($this->schemaHasColumnCached($table, 'currency_id')) {
            $hasRecordUsdtRate = $this->schemaHasColumnCached($table, 'usdt_rate');
            $selectRaw = "user_id, currency_id, SUM({$amountColumn}) as amount_value";

            if ($hasRecordUsdtRate) {
                $selectRaw .= ', usdt_rate';
            }

            $query->selectRaw($selectRaw)
                ->groupBy('user_id', 'currency_id');

            if ($hasRecordUsdtRate) {
                $query->groupBy('usdt_rate');
            }

            foreach ($query->get() as $row) {
                $userId = (int) ($row->user_id ?? 0);

                if ($userId <= 0 || !array_key_exists($userId, $totals)) {
                    continue;
                }

                $totals[$userId] = math_sum(
                    $totals[$userId],
                    $this->convertAmountToUsdt(
                        $row->amount_value ?? 0,
                        (int) ($row->currency_id ?? 0),
                        $hasRecordUsdtRate ? ($row->usdt_rate ?? null) : null,
                        !$hasRecordUsdtRate
                    )
                );
            }

            return $totals;
        }

        foreach (
            $query
                ->selectRaw("user_id, SUM({$amountColumn}) as amount_value")
                ->groupBy('user_id')
                ->get() as $row
        ) {
            $userId = (int) ($row->user_id ?? 0);

            if ($userId > 0 && array_key_exists($userId, $totals)) {
                $totals[$userId] = (string) ($row->amount_value ?? 0);
            }
        }

        return $totals;
    }

    protected function sumAutoInvestBalancesForUsers(array $userIds): array
    {
        $userIds = $this->normalizeIdList($userIds);
        $totals = [];

        foreach ($userIds as $userId) {
            $totals[$userId] = '0';
        }

        if (
            empty($userIds) ||
            !$this->schemaHasTableCached('auto_invest_orders') ||
            !$this->schemaHasColumnCached('auto_invest_orders', 'user_id') ||
            !$this->schemaHasColumnCached('auto_invest_orders', 'currency_id') ||
            !$this->schemaHasColumnCached('auto_invest_orders', 'amount')
        ) {
            return $totals;
        }

        $hasMeta = $this->schemaHasColumnCached('auto_invest_orders', 'meta');
        $query = DB::table('auto_invest_orders')
            ->whereIn('user_id', $userIds);

        if ($this->schemaHasColumnCached('auto_invest_orders', 'status')) {
            $query->where('status', 'active');
        }

        if ($hasMeta) {
            $rows = $query
                ->select(['user_id', 'currency_id', 'amount', 'meta'])
                ->get();
        } else {
            $rows = $query
                ->selectRaw('user_id, currency_id, SUM(amount) as amount_value')
                ->groupBy('user_id', 'currency_id')
                ->get();
        }

        foreach ($rows as $row) {
            if ($hasMeta && $this->isVirtualSourceMeta($row->meta ?? null)) {
                continue;
            }

            $userId = (int) ($row->user_id ?? 0);

            if ($userId <= 0 || !array_key_exists($userId, $totals)) {
                continue;
            }

            $totals[$userId] = math_sum(
                $totals[$userId],
                $this->convertAmountToUsdt(
                    $hasMeta ? ($row->amount ?? 0) : ($row->amount_value ?? 0),
                    (int) ($row->currency_id ?? 0)
                )
            );
        }

        return $totals;
    }

    protected function sumStakingBalancesForUsers(array $userIds): array
    {
        $userIds = $this->normalizeIdList($userIds);
        $totals = [];

        foreach ($userIds as $userId) {
            $totals[$userId] = '0';
        }

        if (
            empty($userIds) ||
            !$this->schemaHasTableCached('staking_users') ||
            !$this->schemaHasColumnCached('staking_users', 'user_id') ||
            !$this->schemaHasColumnCached('staking_users', 'amount')
        ) {
            return $totals;
        }

        $hasCurrencyId = $this->schemaHasColumnCached('staking_users', 'currency_id');
        $hasStakingId = $this->schemaHasColumnCached('staking_users', 'staking_id');
        $hasMeta = $this->schemaHasColumnCached('staking_users', 'meta');
        $query = DB::table('staking_users')->whereIn('user_id', $userIds);

        if ($this->schemaHasColumnCached('staking_users', 'status')) {
            $query->where('status', 'active');
        }

        if ($hasCurrencyId) {
            if ($hasMeta) {
                $rows = $query
                    ->select(['user_id', 'currency_id', 'amount', 'meta'])
                    ->get();
            } else {
                $rows = $query
                    ->selectRaw('user_id, currency_id, SUM(amount) as amount_value')
                    ->groupBy('user_id', 'currency_id')
                    ->get();
            }

            foreach ($rows as $row) {
                if ($hasMeta && $this->isVirtualSourceMeta($row->meta ?? null)) {
                    continue;
                }

                $userId = (int) ($row->user_id ?? 0);

                if ($userId <= 0 || !array_key_exists($userId, $totals)) {
                    continue;
                }

                $totals[$userId] = math_sum(
                    $totals[$userId],
                    $this->convertAmountToUsdt(
                        $hasMeta ? ($row->amount ?? 0) : ($row->amount_value ?? 0),
                        (int) ($row->currency_id ?? 0)
                    )
                );
            }

            return $totals;
        }

        if (!$hasStakingId) {
            return $totals;
        }

        if ($hasMeta) {
            $rows = $query
                ->select(['user_id', 'staking_id', 'amount', 'meta'])
                ->get();
        } else {
            $rows = $query
                ->selectRaw('user_id, staking_id, SUM(amount) as amount_value')
                ->groupBy('user_id', 'staking_id')
                ->get();
        }
        $stakingIds = $rows
            ->pluck('staking_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter(function ($id) {
                return $id > 0;
            })
            ->unique()
            ->values()
            ->all();
        $currencyByStakingId = [];

        if (
            !empty($stakingIds) &&
            $this->schemaHasTableCached('staking') &&
            $this->schemaHasColumnCached('staking', 'currency_id')
        ) {
            $currencyByStakingId = DB::table('staking')
                ->whereIn('id', $stakingIds)
                ->pluck('currency_id', 'id')
                ->map(function ($currencyId) {
                    return (int) $currencyId;
                })
                ->all();
        }

        foreach ($rows as $row) {
            if ($hasMeta && $this->isVirtualSourceMeta($row->meta ?? null)) {
                continue;
            }

            $userId = (int) ($row->user_id ?? 0);
            $stakingId = (int) ($row->staking_id ?? 0);

            if ($userId <= 0 || !array_key_exists($userId, $totals)) {
                continue;
            }

            $totals[$userId] = math_sum(
                $totals[$userId],
                $this->convertAmountToUsdt(
                    $hasMeta ? ($row->amount ?? 0) : ($row->amount_value ?? 0),
                    (int) ($currencyByStakingId[$stakingId] ?? 0)
                )
            );
        }

        return $totals;
    }

    protected function zeroUserBalanceSummary(): array
    {
        $zero = $this->formatAdminBalance(0);

        return [
            'wallet_balance' => $zero,
            'trade_balance' => $zero,
            'total_balance' => $zero,

            'real_wallet_balance' => $zero,
            'real_trade_balance' => $zero,
            'real_order_balance' => $zero,
            'real_withdraw_balance' => $zero,
            'real_total_balance' => $zero,
            'real_locked_total_balance' => $zero,

            'virtual_wallet_balance' => $zero,
            'virtual_trade_balance' => $zero,
            'virtual_order_balance' => $zero,
            'virtual_total_balance' => $zero,
            'virtual_locked_total_balance' => $zero,

            'grand_total_balance' => $zero,
            'locked_total_balance' => $zero,

            'balance_summary' => [
                'real' => [
                    'wallet' => $zero,
                    'trade' => $zero,
                    'order' => $zero,
                    'withdraw' => $zero,
                    'total' => $zero,
                    'locked_total' => $zero,
                ],
                'virtual' => [
                    'wallet' => $zero,
                    'trade' => $zero,
                    'order' => $zero,
                    'total' => $zero,
                    'locked_total' => $zero,
                ],
                'total' => [
                    'available' => $zero,
                    'locked' => $zero,
                ],
            ],
        ];
    }

    protected function buildUserBalanceSummariesForList(array $userIds, array $virtualUserIds = []): array
    {
        $userIds = $this->normalizeIdList($userIds);
        $virtualLookup = array_fill_keys($this->normalizeIdList($virtualUserIds), true);
        $realUserIds = array_values(array_filter($userIds, function ($userId) use ($virtualLookup) {
            return !isset($virtualLookup[$userId]);
        }));
        $summaries = [];

        foreach ($userIds as $userId) {
            $summaries[$userId] = $this->zeroUserBalanceSummary();
        }

        if (
            empty($realUserIds) ||
            !$this->schemaHasTableCached('wallets') ||
            !$this->schemaHasColumnCached('wallets', 'user_id') ||
            !$this->schemaHasColumnCached('wallets', 'currency_id')
        ) {
            return $summaries;
        }

        $walletExpr = $this->schemaHasColumnCached('wallets', 'balance_in_wallet') ? 'COALESCE(balance_in_wallet, 0)' : '0';
        $tradeExpr = $this->schemaHasColumnCached('wallets', 'balance_in_trade') ? 'COALESCE(balance_in_trade, 0)' : '0';
        $orderExpr = $this->schemaHasColumnCached('wallets', 'balance_in_order') ? 'COALESCE(balance_in_order, 0)' : '0';
        $withdrawExpr = $this->schemaHasColumnCached('wallets', 'balance_in_withdraw') ? 'COALESCE(balance_in_withdraw, 0)' : '0';

        $amounts = [];

        foreach ($realUserIds as $userId) {
            $amounts[$userId] = [
                'real_wallet' => '0',
                'real_trade' => '0',
                'real_order' => '0',
                'real_withdraw' => '0',
            ];
        }

        $rows = DB::table('wallets')
            ->whereIn('user_id', $realUserIds)
            ->selectRaw(
                "user_id, currency_id, SUM({$walletExpr}) as real_wallet, SUM({$tradeExpr}) as real_trade, " .
                "SUM({$orderExpr}) as real_order, SUM({$withdrawExpr}) as real_withdraw"
            )
            ->groupBy('user_id', 'currency_id')
            ->get();

        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);

            if ($userId <= 0 || !array_key_exists($userId, $amounts)) {
                continue;
            }

            $currencyId = (int) ($row->currency_id ?? 0);
            $amounts[$userId]['real_wallet'] = math_sum(
                $amounts[$userId]['real_wallet'],
                $this->convertAmountToUsdt($row->real_wallet ?? 0, $currencyId)
            );
            $amounts[$userId]['real_trade'] = math_sum(
                $amounts[$userId]['real_trade'],
                $this->convertAmountToUsdt($row->real_trade ?? 0, $currencyId)
            );
            $amounts[$userId]['real_order'] = math_sum(
                $amounts[$userId]['real_order'],
                $this->convertAmountToUsdt($row->real_order ?? 0, $currencyId)
            );
            $amounts[$userId]['real_withdraw'] = math_sum(
                $amounts[$userId]['real_withdraw'],
                $this->convertAmountToUsdt($row->real_withdraw ?? 0, $currencyId)
            );
        }

        foreach ($amounts as $userId => $amount) {
            $summaries[$userId] = $this->formatUserBalanceSummaryFromAmounts(
                $amount['real_wallet'],
                $amount['real_trade'],
                $amount['real_order'],
                $amount['real_withdraw']
            );
        }

        return $summaries;
    }

    protected function formatUserBalanceSummaryFromAmounts($realWallet, $realTrade, $realOrder, $realWithdraw): array
    {
        $virtualWallet = '0';
        $virtualTrade = '0';
        $virtualOrder = '0';
        $realTotal = math_sum($realWallet, $realTrade);
        $virtualTotal = math_sum($virtualWallet, $virtualTrade);
        $grandTotal = $realTotal;
        $realLockedTotal = math_sum($realOrder, $realWithdraw);
        $virtualLockedTotal = $virtualOrder;
        $lockedTotal = $realLockedTotal;

        return [
            'wallet_balance' => $this->formatAdminBalance($realWallet),
            'trade_balance' => $this->formatAdminBalance($realTrade),
            'total_balance' => $this->formatAdminBalance($grandTotal),

            'real_wallet_balance' => $this->formatAdminBalance($realWallet),
            'real_trade_balance' => $this->formatAdminBalance($realTrade),
            'real_order_balance' => $this->formatAdminBalance($realOrder),
            'real_withdraw_balance' => $this->formatAdminBalance($realWithdraw),
            'real_total_balance' => $this->formatAdminBalance($realTotal),
            'real_locked_total_balance' => $this->formatAdminBalance($realLockedTotal),

            'virtual_wallet_balance' => $this->formatAdminBalance($virtualWallet),
            'virtual_trade_balance' => $this->formatAdminBalance($virtualTrade),
            'virtual_order_balance' => $this->formatAdminBalance($virtualOrder),
            'virtual_total_balance' => $this->formatAdminBalance($virtualTotal),
            'virtual_locked_total_balance' => $this->formatAdminBalance($virtualLockedTotal),

            'grand_total_balance' => $this->formatAdminBalance($grandTotal),
            'locked_total_balance' => $this->formatAdminBalance($lockedTotal),

            'balance_summary' => [
                'real' => [
                    'wallet' => $this->formatAdminBalance($realWallet),
                    'trade' => $this->formatAdminBalance($realTrade),
                    'order' => $this->formatAdminBalance($realOrder),
                    'withdraw' => $this->formatAdminBalance($realWithdraw),
                    'total' => $this->formatAdminBalance($realTotal),
                    'locked_total' => $this->formatAdminBalance($realLockedTotal),
                ],
                'virtual' => [
                    'wallet' => $this->formatAdminBalance($virtualWallet),
                    'trade' => $this->formatAdminBalance($virtualTrade),
                    'order' => $this->formatAdminBalance($virtualOrder),
                    'total' => $this->formatAdminBalance($virtualTotal),
                    'locked_total' => $this->formatAdminBalance($virtualLockedTotal),
                ],
                'total' => [
                    'available' => $this->formatAdminBalance($grandTotal),
                    'locked' => $this->formatAdminBalance($lockedTotal),
                ],
            ],
        ];
    }

    protected function getUsersWithTeamChildren(array $userIds): array
    {
        $userIds = $this->normalizeIdList($userIds);

        if (empty($userIds)) {
            return [];
        }

        $ids = User::query()
            ->whereIn('referral_id', $userIds)
            ->distinct()
            ->pluck('referral_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter(function ($id) {
                return $id > 0;
            })
            ->values()
            ->all();

        return array_fill_keys($ids, true);
    }

    protected function prefetchReferrerUsersForCollection($users): array
    {
        $cache = [];
        $pendingIds = $users
            ->pluck('referral_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter(function ($id) {
                return $id > 0;
            })
            ->unique()
            ->values()
            ->all();
        $visitedIds = [];
        $depth = 0;

        while (!empty($pendingIds) && $depth < 50) {
            $pendingIds = array_values(array_diff($this->normalizeIdList($pendingIds), $visitedIds));

            if (empty($pendingIds)) {
                break;
            }

            $parents = User::query()
                ->with('roles')
                ->select([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'wallet_id',
                    'referral_id',
                    'referral_code',
                    'nickname',
                    'leader_nickname',
                ])
                ->whereIn('id', $pendingIds)
                ->get();

            $nextIds = [];

            foreach ($parents as $parent) {
                $parentId = (int) $parent->id;
                $cache[$parentId] = $parent;

                if (!empty($parent->referral_id)) {
                    $nextIds[] = (int) $parent->referral_id;
                }
            }

            $visitedIds = array_values(array_unique(array_merge($visitedIds, $pendingIds)));
            $pendingIds = $nextIds;
            $depth++;
        }

        return $cache;
    }

    protected function resolveLeaderDisplayNameForListFromCache(User $user, array &$userCache): string
    {
        $user->loadMissing('roles');

        $leaderNickname = trim((string) ($user->leader_nickname ?? ''));
        $nickname = trim((string) ($user->nickname ?? ''));

        if ($user->hasRole('user_leader')) {
            return $leaderNickname !== '' ? $leaderNickname : $nickname;
        }

        $referralId = $user->referral_id ?? null;
        $visited = [];
        $depth = 0;

        while ($referralId && $depth < 50) {
            $referralId = (int) $referralId;

            if ($referralId <= 0 || in_array($referralId, $visited, true)) {
                break;
            }

            $visited[] = $referralId;
            $parent = $this->getUserReferrerFromCache($referralId, $userCache);

            if (!$parent) {
                break;
            }

            $parent->loadMissing('roles');

            if ($parent->hasRole('user_leader')) {
                return $this->formatLeaderName($parent);
            }

            $referralId = $parent->referral_id ?? null;
            $depth++;
        }

        return '';
    }

    protected function resolveIpLocationForUserList($user): string
    {
        $storedLocation = trim((string) ($user->ip_location ?? ''));

        if ($storedLocation !== '') {
            $translatedStoredLocation = $this->translateStoredIpLocationLabelToChinese($storedLocation);

            if ($translatedStoredLocation !== '' && $translatedStoredLocation !== $storedLocation) {
                $this->saveUserIpLocationForList($user, $translatedStoredLocation, true);

                return $translatedStoredLocation;
            }

            return $storedLocation;
        }

        $ip = trim((string) ($user->login_ip ?? ''));

        if ($ip === '') {
            return '';
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        if ($this->isPrivateIpAddress($ip)) {
            return '本地/内网';
        }

        $cacheKey = 'user_ip_location_label:' . md5($ip);
        $cachedLocation = Cache::get($cacheKey);

        if (is_string($cachedLocation) && $cachedLocation !== '') {
            $translatedCachedLocation = $this->translateStoredIpLocationLabelToChinese($cachedLocation);
            $location = $translatedCachedLocation !== '' ? $translatedCachedLocation : $cachedLocation;

            $this->saveUserIpLocationForList($user, $location);

            return $location;
        }

        /*
         * 后台用户列表是高频页面，不能在这里同步请求外部 IP 归属地服务。
         * 外部接口慢或不可用时会占满 PHP-FPM 进程，导致全站请求排队。
         */
        return '';
    }

    protected function resolveIpLocation(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if ($this->isPrivateIpAddress($ip)) {
            return '本地/内网';
        }

        $location = $this->lookupPublicIpLocationLabel($ip);

        return $location !== '' ? $location : null;
    }

    protected function lookupPublicIpLocationLabel(string $ip): string
    {
        $cacheKey = 'user_ip_location_label:' . md5($ip);
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            $translatedCached = $this->translateStoredIpLocationLabelToChinese($cached);

            if ($translatedCached !== '' && $translatedCached !== $cached) {
                Cache::put($cacheKey, $translatedCached, now()->addDays(30));

                return $translatedCached;
            }

            return $cached;
        }

        $location = $this->formatIpLocationLabel($this->queryIpWhoIsLocation($ip));

        if ($location === '') {
            $location = $this->formatIpLocationLabel($this->queryIpApiCoLocation($ip));
        }

        /*
         * 查到归属地缓存 30 天。
         * 查询失败只缓存 6 小时，避免第三方 API 短暂故障后长期空白。
         */
        Cache::put(
            $cacheKey,
            $location,
            $location !== '' ? now()->addDays(30) : now()->addHours(6)
        );

        return $location;
    }

    protected function queryIpWhoIsLocation(string $ip): array
    {
        try {
            $response = Http::timeout(3)
                ->retry(1, 200)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                ])
                ->get('https://ipwho.is/' . $ip . '?lang=zh-CN');

            if (!$response->ok()) {
                return [];
            }

            $data = $response->json();

            if (!is_array($data) || empty($data['success'])) {
                return [];
            }

            return [
                'country' => $data['country'] ?? null,
                'country_code' => $data['country_code'] ?? null,
                'region' => $data['region'] ?? null,
                'city' => $data['city'] ?? null,
                'source' => 'ipwho.is',
            ];
        } catch (\Throwable $e) {
            Log::debug('IP location lookup failed.', [
                'provider' => 'ipwho.is',
                'ip' => $ip,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function queryIpApiCoLocation(string $ip): array
    {
        try {
            $response = Http::timeout(3)
                ->retry(1, 200)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                ])
                ->get('https://ipapi.co/' . $ip . '/json/');

            if (!$response->ok()) {
                return [];
            }

            $data = $response->json();

            if (!is_array($data) || !empty($data['error'])) {
                return [];
            }

            return [
                'country' => $data['country_name'] ?? null,
                'country_code' => $data['country_code'] ?? null,
                'region' => $data['region'] ?? null,
                'city' => $data['city'] ?? null,
                'source' => 'ipapi.co',
            ];
        } catch (\Throwable $e) {
            Log::debug('IP location lookup failed.', [
                'provider' => 'ipapi.co',
                'ip' => $ip,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function formatIpLocationLabel(array $location): string
    {
        if (empty($location)) {
            return '';
        }

        $location = $this->translateIpLocationToChinese($location);

        $country = trim((string) ($location['country'] ?? ''));
        $countryCode = strtoupper(trim((string) ($location['country_code'] ?? '')));
        $region = trim((string) ($location['region'] ?? ''));
        $city = trim((string) ($location['city'] ?? ''));

        $parts = [];

        if ($country !== '') {
            $parts[] = $countryCode !== '' ? "{$country}({$countryCode})" : $country;
        } elseif ($countryCode !== '') {
            $parts[] = $countryCode;
        }

        $this->addIpLocationPart($parts, $region);
        $this->addIpLocationPart($parts, $city);

        return implode(' / ', $parts);
    }

    protected function addIpLocationPart(array &$parts, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        foreach ($parts as $part) {
            if ($this->normalizeIpLocationText($part) === $this->normalizeIpLocationText($value)) {
                return;
            }
        }

        $parts[] = $value;
    }

    protected function normalizeIpLocationText(string $value): string
    {
        $value = preg_replace('/\([A-Z]{2}\)$/', '', trim($value));

        return strtolower(trim((string) $value));
    }

    protected function translateIpLocationToChinese(array $location): array
    {
        $countryCode = strtoupper(trim((string) ($location['country_code'] ?? '')));

        $location['country'] = $this->getChineseCountryName($countryCode, $location['country'] ?? null);
        $location['region'] = $this->getChineseRegionName($countryCode, $location['region'] ?? null);
        $location['city'] = $this->getChineseCityName($countryCode, $location['city'] ?? null);

        return $location;
    }

    protected function translateStoredIpLocationLabelToChinese(string $label): string
    {
        $label = trim($label);

        if ($label === '' || $label === '本地/内网') {
            return $label;
        }

        $directMap = [
            'Spain(ES) / Castilla-La Mancha / Villarrobledo' => '西班牙(ES) / 卡斯蒂利亚-拉曼恰 / 比利亚罗夫莱多',
            'Spain (ES) / Castilla-La Mancha / Villarrobledo' => '西班牙(ES) / 卡斯蒂利亚-拉曼恰 / 比利亚罗夫莱多',
        ];

        if (isset($directMap[$label])) {
            return $directMap[$label];
        }

        $rawParts = array_values(array_filter(array_map('trim', explode('/', $label)), function ($part) {
            return $part !== '';
        }));

        if (empty($rawParts)) {
            return $label;
        }

        $countryCode = '';
        $countryPart = $rawParts[0];

        if (preg_match('/\(([A-Z]{2})\)$/', $countryPart, $matches)) {
            $countryCode = strtoupper($matches[1]);
        } elseif (preg_match('/^[A-Z]{2}$/', strtoupper($countryPart))) {
            $countryCode = strtoupper($countryPart);
        }

        $parts = [];

        foreach ($rawParts as $index => $part) {
            $part = trim($part);

            if ($index === 0) {
                $countryName = trim((string) preg_replace('/\([A-Z]{2}\)$/', '', $part));
                $countryName = $this->getChineseCountryName($countryCode, $countryName);

                if ($countryName !== '') {
                    $part = $countryCode !== '' ? $countryName . '(' . $countryCode . ')' : $countryName;
                }
            } elseif ($index === 1) {
                $part = $this->getChineseRegionName($countryCode, $part);
            } else {
                $part = $this->getChineseCityName($countryCode, $part);
            }

            $this->addIpLocationPart($parts, $part);
        }

        return !empty($parts) ? implode(' / ', $parts) : $label;
    }

    protected function getChineseCountryName(?string $countryCode, ?string $fallback = null): string
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        $fallback = trim((string) $fallback);

        $map = [
            'AD' => '安道尔', 'AE' => '阿联酋', 'AF' => '阿富汗', 'AG' => '安提瓜和巴布达', 'AI' => '安圭拉',
            'AL' => '阿尔巴尼亚', 'AM' => '亚美尼亚', 'AO' => '安哥拉', 'AQ' => '南极洲', 'AR' => '阿根廷',
            'AS' => '美属萨摩亚', 'AT' => '奥地利', 'AU' => '澳大利亚', 'AW' => '阿鲁巴', 'AX' => '奥兰群岛',
            'AZ' => '阿塞拜疆', 'BA' => '波黑', 'BB' => '巴巴多斯', 'BD' => '孟加拉国', 'BE' => '比利时',
            'BF' => '布基纳法索', 'BG' => '保加利亚', 'BH' => '巴林', 'BI' => '布隆迪', 'BJ' => '贝宁',
            'BL' => '圣巴泰勒米', 'BM' => '百慕大', 'BN' => '文莱', 'BO' => '玻利维亚', 'BQ' => '荷兰加勒比区',
            'BR' => '巴西', 'BS' => '巴哈马', 'BT' => '不丹', 'BV' => '布韦岛', 'BW' => '博茨瓦纳',
            'BY' => '白俄罗斯', 'BZ' => '伯利兹', 'CA' => '加拿大', 'CC' => '科科斯群岛', 'CD' => '刚果（金）',
            'CF' => '中非共和国', 'CG' => '刚果（布）', 'CH' => '瑞士', 'CI' => '科特迪瓦', 'CK' => '库克群岛',
            'CL' => '智利', 'CM' => '喀麦隆', 'CN' => '中国', 'CO' => '哥伦比亚', 'CR' => '哥斯达黎加',
            'CU' => '古巴', 'CV' => '佛得角', 'CW' => '库拉索', 'CX' => '圣诞岛', 'CY' => '塞浦路斯',
            'CZ' => '捷克', 'DE' => '德国', 'DJ' => '吉布提', 'DK' => '丹麦', 'DM' => '多米尼克',
            'DO' => '多米尼加', 'DZ' => '阿尔及利亚', 'EC' => '厄瓜多尔', 'EE' => '爱沙尼亚', 'EG' => '埃及',
            'EH' => '西撒哈拉', 'ER' => '厄立特里亚', 'ES' => '西班牙', 'ET' => '埃塞俄比亚', 'FI' => '芬兰',
            'FJ' => '斐济', 'FK' => '福克兰群岛', 'FM' => '密克罗尼西亚', 'FO' => '法罗群岛', 'FR' => '法国',
            'GA' => '加蓬', 'GB' => '英国', 'GD' => '格林纳达', 'GE' => '格鲁吉亚', 'GF' => '法属圭亚那',
            'GG' => '根西岛', 'GH' => '加纳', 'GI' => '直布罗陀', 'GL' => '格陵兰', 'GM' => '冈比亚',
            'GN' => '几内亚', 'GP' => '瓜德罗普', 'GQ' => '赤道几内亚', 'GR' => '希腊', 'GS' => '南乔治亚和南桑威奇群岛',
            'GT' => '危地马拉', 'GU' => '关岛', 'GW' => '几内亚比绍', 'GY' => '圭亚那', 'HK' => '中国香港',
            'HM' => '赫德岛和麦克唐纳群岛', 'HN' => '洪都拉斯', 'HR' => '克罗地亚', 'HT' => '海地', 'HU' => '匈牙利',
            'ID' => '印度尼西亚', 'IE' => '爱尔兰', 'IL' => '以色列', 'IM' => '马恩岛', 'IN' => '印度',
            'IO' => '英属印度洋领地', 'IQ' => '伊拉克', 'IR' => '伊朗', 'IS' => '冰岛', 'IT' => '意大利',
            'JE' => '泽西岛', 'JM' => '牙买加', 'JO' => '约旦', 'JP' => '日本', 'KE' => '肯尼亚',
            'KG' => '吉尔吉斯斯坦', 'KH' => '柬埔寨', 'KI' => '基里巴斯', 'KM' => '科摩罗', 'KN' => '圣基茨和尼维斯',
            'KP' => '朝鲜', 'KR' => '韩国', 'KW' => '科威特', 'KY' => '开曼群岛', 'KZ' => '哈萨克斯坦',
            'LA' => '老挝', 'LB' => '黎巴嫩', 'LC' => '圣卢西亚', 'LI' => '列支敦士登', 'LK' => '斯里兰卡',
            'LR' => '利比里亚', 'LS' => '莱索托', 'LT' => '立陶宛', 'LU' => '卢森堡', 'LV' => '拉脱维亚',
            'LY' => '利比亚', 'MA' => '摩洛哥', 'MC' => '摩纳哥', 'MD' => '摩尔多瓦', 'ME' => '黑山',
            'MF' => '法属圣马丁', 'MG' => '马达加斯加', 'MH' => '马绍尔群岛', 'MK' => '北马其顿', 'ML' => '马里',
            'MM' => '缅甸', 'MN' => '蒙古', 'MO' => '中国澳门', 'MP' => '北马里亚纳群岛', 'MQ' => '马提尼克',
            'MR' => '毛里塔尼亚', 'MS' => '蒙特塞拉特', 'MT' => '马耳他', 'MU' => '毛里求斯', 'MV' => '马尔代夫',
            'MW' => '马拉维', 'MX' => '墨西哥', 'MY' => '马来西亚', 'MZ' => '莫桑比克', 'NA' => '纳米比亚',
            'NC' => '新喀里多尼亚', 'NE' => '尼日尔', 'NF' => '诺福克岛', 'NG' => '尼日利亚', 'NI' => '尼加拉瓜',
            'NL' => '荷兰', 'NO' => '挪威', 'NP' => '尼泊尔', 'NR' => '瑙鲁', 'NU' => '纽埃',
            'NZ' => '新西兰', 'OM' => '阿曼', 'PA' => '巴拿马', 'PE' => '秘鲁', 'PF' => '法属波利尼西亚',
            'PG' => '巴布亚新几内亚', 'PH' => '菲律宾', 'PK' => '巴基斯坦', 'PL' => '波兰', 'PM' => '圣皮埃尔和密克隆',
            'PN' => '皮特凯恩群岛', 'PR' => '波多黎各', 'PS' => '巴勒斯坦', 'PT' => '葡萄牙', 'PW' => '帕劳',
            'PY' => '巴拉圭', 'QA' => '卡塔尔', 'RE' => '留尼汪', 'RO' => '罗马尼亚', 'RS' => '塞尔维亚',
            'RU' => '俄罗斯', 'RW' => '卢旺达', 'SA' => '沙特阿拉伯', 'SB' => '所罗门群岛', 'SC' => '塞舌尔',
            'SD' => '苏丹', 'SE' => '瑞典', 'SG' => '新加坡', 'SH' => '圣赫勒拿', 'SI' => '斯洛文尼亚',
            'SJ' => '斯瓦尔巴和扬马延', 'SK' => '斯洛伐克', 'SL' => '塞拉利昂', 'SM' => '圣马力诺', 'SN' => '塞内加尔',
            'SO' => '索马里', 'SR' => '苏里南', 'SS' => '南苏丹', 'ST' => '圣多美和普林西比', 'SV' => '萨尔瓦多',
            'SX' => '荷属圣马丁', 'SY' => '叙利亚', 'SZ' => '斯威士兰', 'TC' => '特克斯和凯科斯群岛', 'TD' => '乍得',
            'TF' => '法属南部领地', 'TG' => '多哥', 'TH' => '泰国', 'TJ' => '塔吉克斯坦', 'TK' => '托克劳',
            'TL' => '东帝汶', 'TM' => '土库曼斯坦', 'TN' => '突尼斯', 'TO' => '汤加', 'TR' => '土耳其',
            'TT' => '特立尼达和多巴哥', 'TV' => '图瓦卢', 'TW' => '中国台湾', 'TZ' => '坦桑尼亚', 'UA' => '乌克兰',
            'UG' => '乌干达', 'UM' => '美国本土外小岛屿', 'US' => '美国', 'UY' => '乌拉圭', 'UZ' => '乌兹别克斯坦',
            'VA' => '梵蒂冈', 'VC' => '圣文森特和格林纳丁斯', 'VE' => '委内瑞拉', 'VG' => '英属维尔京群岛', 'VI' => '美属维尔京群岛',
            'VN' => '越南', 'VU' => '瓦努阿图', 'WF' => '瓦利斯和富图纳', 'WS' => '萨摩亚', 'YE' => '也门',
            'YT' => '马约特', 'ZA' => '南非', 'ZM' => '赞比亚', 'ZW' => '津巴布韦',
        ];

        if ($countryCode !== '' && isset($map[$countryCode])) {
            return $map[$countryCode];
        }

        $fallbackMap = [
            'spain' => '西班牙', 'france' => '法国', 'china' => '中国', 'hong kong' => '中国香港', 'taiwan' => '中国台湾',
            'singapore' => '新加坡', 'japan' => '日本', 'south korea' => '韩国', 'korea, republic of' => '韩国',
            'united states' => '美国', 'united kingdom' => '英国', 'germany' => '德国', 'italy' => '意大利',
            'russia' => '俄罗斯', 'ukraine' => '乌克兰', 'poland' => '波兰', 'bulgaria' => '保加利亚',
        ];

        $key = strtolower($fallback);

        return $fallbackMap[$key] ?? $fallback;
    }

    protected function getChineseRegionName(?string $countryCode, ?string $region): string
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        $region = trim((string) $region);

        if ($region === '') {
            return '';
        }

        $maps = $this->getIpRegionChineseMaps();
        $key = $this->normalizeIpLocationMapKey($region);

        if ($countryCode !== '' && isset($maps[$countryCode][$key])) {
            return $maps[$countryCode][$key];
        }

        if (isset($maps['GLOBAL'][$key])) {
            return $maps['GLOBAL'][$key];
        }

        return $region;
    }

    protected function getChineseCityName(?string $countryCode, ?string $city): string
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        $city = trim((string) $city);

        if ($city === '') {
            return '';
        }

        $maps = $this->getIpCityChineseMaps();
        $key = $this->normalizeIpLocationMapKey($city);

        if ($countryCode !== '' && isset($maps[$countryCode][$key])) {
            return $maps[$countryCode][$key];
        }

        if (isset($maps['GLOBAL'][$key])) {
            return $maps['GLOBAL'][$key];
        }

        return $city;
    }

    protected function getIpRegionChineseMaps(): array
    {
        return [
            'ES' => [
                'community of madrid' => '马德里自治区', 'madrid' => '马德里自治区', 'catalonia' => '加泰罗尼亚',
                'catalunya' => '加泰罗尼亚', 'andalusia' => '安达卢西亚', 'andalucia' => '安达卢西亚',
                'valencian community' => '瓦伦西亚自治区', 'valencia' => '瓦伦西亚自治区', 'galicia' => '加利西亚',
                'basque country' => '巴斯克地区', 'euskadi' => '巴斯克地区', 'castile and leon' => '卡斯蒂利亚-莱昂',
                'castille and leon' => '卡斯蒂利亚-莱昂', 'castilla y leon' => '卡斯蒂利亚-莱昂', 'castilla-la mancha' => '卡斯蒂利亚-拉曼恰',
                'castilla la mancha' => '卡斯蒂利亚-拉曼恰', 'castile-la mancha' => '卡斯蒂利亚-拉曼恰',
                'castile la mancha' => '卡斯蒂利亚-拉曼恰',
                'canary islands' => '加那利群岛', 'islas canarias' => '加那利群岛', 'balearic islands' => '巴利阿里群岛',
                'aragon' => '阿拉贡', 'asturias' => '阿斯图里亚斯', 'cantabria' => '坎塔布里亚',
                'extremadura' => '埃斯特雷马杜拉', 'la rioja' => '拉里奥哈', 'navarre' => '纳瓦拉',
                'region of murcia' => '穆尔西亚地区', 'murcia' => '穆尔西亚地区',
            ],
            'FR' => [
                'ile-de-france' => '法兰西岛', 'île-de-france' => '法兰西岛', 'idf' => '法兰西岛',
                'provence-alpes-cote d azur' => '普罗旺斯-阿尔卑斯-蓝色海岸', 'provence-alpes-côte d azur' => '普罗旺斯-阿尔卑斯-蓝色海岸',
                'auvergne-rhone-alpes' => '奥弗涅-罗讷-阿尔卑斯', 'auvergne-rhône-alpes' => '奥弗涅-罗讷-阿尔卑斯',
                'nouvelle-aquitaine' => '新阿基坦', 'occitanie' => '奥克西塔尼', 'grand est' => '大东部',
                'hauts-de-france' => '上法兰西', 'bretagne' => '布列塔尼', 'brittany' => '布列塔尼',
                'normandie' => '诺曼底', 'normandy' => '诺曼底', 'pays de la loire' => '卢瓦尔河地区',
                'centre-val de loire' => '中央-卢瓦尔河谷', 'bourgogne-franche-comte' => '勃艮第-弗朗什-孔泰',
                'bourgogne-franche-comté' => '勃艮第-弗朗什-孔泰', 'corse' => '科西嘉', 'corsica' => '科西嘉',
            ],
            'CN' => [
                'beijing' => '北京', 'shanghai' => '上海', 'tianjin' => '天津', 'chongqing' => '重庆',
                'guangdong' => '广东', 'zhejiang' => '浙江', 'jiangsu' => '江苏', 'fujian' => '福建', 'shandong' => '山东',
                'henan' => '河南', 'hubei' => '湖北', 'hunan' => '湖南', 'sichuan' => '四川', 'yunnan' => '云南',
                'guangxi' => '广西', 'hainan' => '海南', 'anhui' => '安徽', 'jiangxi' => '江西', 'hebei' => '河北',
                'shanxi' => '山西', 'shaanxi' => '陕西', 'liaoning' => '辽宁', 'jilin' => '吉林', 'heilongjiang' => '黑龙江',
                'guizhou' => '贵州', 'gansu' => '甘肃', 'qinghai' => '青海', 'inner mongolia' => '内蒙古', 'ningxia' => '宁夏',
                'xinjiang' => '新疆', 'tibet' => '西藏',
            ],
            'US' => [
                'california' => '加利福尼亚州', 'new york' => '纽约州', 'texas' => '得克萨斯州', 'florida' => '佛罗里达州',
                'washington' => '华盛顿州', 'illinois' => '伊利诺伊州', 'new jersey' => '新泽西州', 'virginia' => '弗吉尼亚州',
            ],
            'JP' => ['tokyo' => '东京都', 'osaka' => '大阪府', 'kanagawa' => '神奈川县', 'kyoto' => '京都府', 'aichi' => '爱知县', 'fukuoka' => '福冈县'],
            'KR' => ['seoul' => '首尔', 'busan' => '釜山', 'incheon' => '仁川', 'gyeonggi-do' => '京畿道'],
            'GLOBAL' => [
                'hong kong' => '香港', 'macau' => '澳门', 'taipei' => '台北', 'singapore' => '新加坡',
                'london' => '伦敦', 'new york' => '纽约', 'tokyo' => '东京', 'paris' => '巴黎', 'madrid' => '马德里',
            ],
        ];
    }

    protected function getIpCityChineseMaps(): array
    {
        return [
            'ES' => [
                'madrid' => '马德里', 'barcelona' => '巴塞罗那', 'valencia' => '瓦伦西亚', 'seville' => '塞维利亚',
                'sevilla' => '塞维利亚', 'zaragoza' => '萨拉戈萨', 'malaga' => '马拉加', 'murcia' => '穆尔西亚',
                'palma' => '帕尔马', 'las palmas' => '拉斯帕尔马斯', 'bilbao' => '毕尔巴鄂', 'alicante' => '阿利坎特',
                'cordoba' => '科尔多瓦', 'córdoba' => '科尔多瓦', 'valladolid' => '巴利亚多利德', 'vigo' => '维戈',
                'gijon' => '希洪', 'gijón' => '希洪', 'granada' => '格拉纳达', 'a coruna' => '拉科鲁尼亚', 'a coruña' => '拉科鲁尼亚',
                'villarrobledo' => '比利亚罗夫莱多', 'toledo' => '托莱多', 'albacete' => '阿尔瓦塞特',
                'ciudad real' => '雷阿尔城', 'cuenca' => '昆卡', 'guadalajara' => '瓜达拉哈拉',
            ],
            'FR' => [
                'paris' => '巴黎', 'marseille' => '马赛', 'lyon' => '里昂', 'toulouse' => '图卢兹', 'nice' => '尼斯',
                'nantes' => '南特', 'montpellier' => '蒙彼利埃', 'strasbourg' => '斯特拉斯堡', 'bordeaux' => '波尔多',
                'lille' => '里尔', 'rennes' => '雷恩', 'reims' => '兰斯', 'le havre' => '勒阿弗尔', 'saint-etienne' => '圣艾蒂安',
                'saint-étienne' => '圣艾蒂安', 'toulon' => '土伦', 'grenoble' => '格勒诺布尔', 'dijon' => '第戎', 'angers' => '昂热',
            ],
            'CN' => [
                'beijing' => '北京', 'shanghai' => '上海', 'guangzhou' => '广州', 'shenzhen' => '深圳', 'hangzhou' => '杭州',
                'nanjing' => '南京', 'chengdu' => '成都', 'chongqing' => '重庆', 'wuhan' => '武汉', 'xian' => '西安', "xi'an" => '西安',
                'suzhou' => '苏州', 'tianjin' => '天津', 'qingdao' => '青岛', 'xiamen' => '厦门', 'fuzhou' => '福州',
            ],
            'HK' => ['hong kong' => '香港'],
            'MO' => ['macau' => '澳门', 'macao' => '澳门'],
            'TW' => ['taipei' => '台北', 'kaohsiung' => '高雄', 'taichung' => '台中', 'tainan' => '台南'],
            'SG' => ['singapore' => '新加坡'],
            'JP' => ['tokyo' => '东京', 'osaka' => '大阪', 'kyoto' => '京都', 'yokohama' => '横滨', 'nagoya' => '名古屋', 'fukuoka' => '福冈'],
            'KR' => ['seoul' => '首尔', 'busan' => '釜山', 'incheon' => '仁川', 'daegu' => '大邱', 'daejeon' => '大田'],
            'US' => ['new york' => '纽约', 'los angeles' => '洛杉矶', 'san francisco' => '旧金山', 'chicago' => '芝加哥', 'washington' => '华盛顿', 'seattle' => '西雅图'],
            'GB' => ['london' => '伦敦', 'manchester' => '曼彻斯特', 'birmingham' => '伯明翰', 'liverpool' => '利物浦', 'glasgow' => '格拉斯哥'],
            'DE' => ['berlin' => '柏林', 'hamburg' => '汉堡', 'munich' => '慕尼黑', 'münchen' => '慕尼黑', 'frankfurt' => '法兰克福'],
            'IT' => ['rome' => '罗马', 'roma' => '罗马', 'milan' => '米兰', 'milano' => '米兰', 'naples' => '那不勒斯', 'turin' => '都灵'],
            'GLOBAL' => [
                'hong kong' => '香港', 'macau' => '澳门', 'macao' => '澳门', 'singapore' => '新加坡',
                'paris' => '巴黎', 'madrid' => '马德里', 'london' => '伦敦', 'new york' => '纽约', 'tokyo' => '东京',
            ],
        ];
    }

    protected function normalizeIpLocationMapKey(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['’', '`', '´'], "'", $value);
        $value = str_replace(['–', '—', '_'], '-', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = str_replace(['.', ','], '', $value);
        $value = str_replace('&', 'and', $value);

        return trim($value);
    }

    protected function saveUserIpLocationForList($user, string $location, bool $replaceExisting = false): void
    {
        $userId = (int) ($user->id ?? 0);

        if (
            $userId <= 0 ||
            $location === '' ||
            !Schema::hasTable('users') ||
            !Schema::hasColumn('users', 'ip_location')
        ) {
            return;
        }

        try {
            $query = DB::table('users')->where('id', $userId);

            if (!$replaceExisting) {
                $query->where(function ($query) {
                    $query->whereNull('ip_location')
                        ->orWhere('ip_location', '');
                });
            }

            $query->update([
                'ip_location' => $location,
            ]);

            $user->ip_location = $location;
        } catch (\Throwable $e) {
            Log::debug('Failed to save user IP location.', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function isPrivateIpAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
    /**
     * 每一级团队实时查询接口。
     * 前端每点击一个节点，就调用这个接口查该节点的直属下级。
     *
     * URL:
     * /exchange-control-panel/users/team-children?parent_id=用户ID
     */
    public function teamChildren(Request $request)
    {
        $parentId = (int) $request->get('parent_id');

        if (!$parentId) {
            return response()->json([
                'message' => 'Parent user id is required.',
                'children' => [],
            ], 422);
        }

        if (!$this->canViewTeamChildren($parentId)) {
            return response()->json([
                'message' => 'Forbidden',
                'children' => [],
            ], 403);
        }

        return response()->json([
            'children' => $this->getDirectTeamChildren($parentId),
        ]);
    }

    /**
     * 单独团队页面。
     *
     * URL:
     * /exchange-control-panel/users/team-view?parent_id=用户ID
     */
    public function teamView(Request $request)
    {
        $parentId = (int) $request->get('parent_id');

        if (!$parentId) {
            abort(404);
        }

        if (!$this->canViewTeamChildren($parentId)) {
            abort(403);
        }

        $levelData = $this->buildTeamBlankLevelData($parentId);

        $apiUrl = route('admin.users.team.level');

        $initialData = json_encode([
            'parent' => $levelData['parent'],
            'children' => $levelData['children'],
            'summary' => $levelData['summary'],
            'apiUrl' => $apiUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response()->view('admin.team-level', ['initialData' => json_decode($initialData, true)]);
    }

    /**
     * 单独团队页，每点击一级查询下一级。
     *
     * URL:
     * /exchange-control-panel/users/team-level?parent_id=用户ID
     */
    public function teamLevel(Request $request)
    {
        $parentId = (int) $request->get('parent_id');

        if (!$parentId) {
            return response()->json([
                'message' => 'Parent user id is required.',
                'parent' => null,
                'children' => [],
                'summary' => $this->emptyTeamSummary(),
            ], 422);
        }

        if (!$this->canViewTeamChildren($parentId)) {
            return response()->json([
                'message' => 'Forbidden',
                'parent' => null,
                'children' => [],
                'summary' => $this->emptyTeamSummary(),
            ], 403);
        }

        return response()->json($this->buildTeamBlankLevelData($parentId));
    }

    /**
     * 兼容旧的 users/fetch。
     * 如果传 team_parent_id，也返回该用户直属下级。
     */
    public function fetch(Request $request)
    {
        if ($request->filled('team_parent_id')) {
            $parentId = (int) $request->get('team_parent_id');

            if (!$parentId || !$this->canViewTeamChildren($parentId)) {
                return response()->json([
                    'children' => [],
                    'message' => 'Forbidden',
                ], 403);
            }

            return response()->json([
                'children' => $this->getDirectTeamChildren($parentId),
            ]);
        }

        $q = $request->get('q');

        $users = $this->userRepository->fetchByEmail($q);

        return response()->json(['users' => $users]);
    }

    protected function canViewTeamChildren(int $parentId): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return false;
        }

        $currentUser->loadMissing('roles');

        $roleNames = $currentUser->roles->pluck('name')->filter()->values()->toArray();
        $roleIds = $currentUser->roles->pluck('id')->map(function ($id) {
            return (int) $id;
        })->toArray();

        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

        if ($isSuperAdmin) {
            return true;
        }

        $allowedRoles = [
            'admin',
            'user_editor',
            'user_leader',
            'salesman',
            'perm_users',
        ];

        $hasTeamPermission = count(array_intersect($roleNames, $allowedRoles)) > 0 || (auth()->user()?->hasRole('admin') ?? false);

        if (!$hasTeamPermission) {
            return false;
        }

        $myTeamUserIds = array_map('intval', $this->getAllTeamUserIds((int) $currentUser->id));

        return in_array((int) $parentId, $myTeamUserIds, true);
    }

    protected function getDirectTeamChildren(int $parentId): array
    {
        $children = User::query()
            ->with('roles')
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'wallet_id',
                'referral_id',
                'referral_code',
                'nickname',
                'leader_nickname',
                'created_at',
                'deactivated',
                'email_verified_at',
                'kyc_verified_at',
                'is_t',
            ])
            ->where('referral_id', $parentId)
            ->orderByDesc('id')
            ->get();

        return $children->map(function (User $child) {
            return $this->formatTeamNode($child);
        })->values()->toArray();
    }

    protected function formatTeamNode(User $child): array
    {
        $balance = $this->buildUserBalanceSummary((int) $child->id);

        return [
            'id' => $child->id,
            'name' => $child->name,
            'email' => $child->email,
            'phone' => $child->phone,
            'wallet_id' => $child->wallet_id,
            'referral_id' => $child->referral_id,
            'referral_code' => $child->referral_code,
            'nickname' => trim((string) ($child->nickname ?? '')),
            'leader_nickname' => trim((string) ($child->leader_nickname ?? '')),
            'leader_display_name' => $this->resolveLeaderDisplayNameForList($child),
            'display_name' => $this->formatUserDisplayName($child),
            'created_at' => $child->created_at ? Carbon::parse($child->created_at)->format('Y-m-d H:i:s') : '',
            'deactivated' => (bool) $child->deactivated,
            'email_verified_at' => $child->email_verified_at,
            'kyc_verified_at' => $child->kyc_verified_at,
            'is_t' => (bool) $child->is_t,
            'has_children' => $this->hasTeamChildren((int) $child->id),
            'children_loaded' => false,
            'children' => [],
            'team_children' => [],

            'wallet_balance' => $balance['wallet_balance'],
            'trade_balance' => $balance['trade_balance'],
            'total_balance' => $balance['total_balance'],

            'real_wallet_balance' => $balance['real_wallet_balance'],
            'real_trade_balance' => $balance['real_trade_balance'],
            'real_order_balance' => $balance['real_order_balance'],
            'real_withdraw_balance' => $balance['real_withdraw_balance'],
            'real_total_balance' => $balance['real_total_balance'],
            'real_locked_total_balance' => $balance['real_locked_total_balance'],

            'virtual_wallet_balance' => $balance['virtual_wallet_balance'],
            'virtual_trade_balance' => $balance['virtual_trade_balance'],
            'virtual_order_balance' => $balance['virtual_order_balance'],
            'virtual_total_balance' => $balance['virtual_total_balance'],
            'virtual_locked_total_balance' => $balance['virtual_locked_total_balance'],

            'grand_total_balance' => $balance['grand_total_balance'],
            'locked_total_balance' => $balance['locked_total_balance'],
            'balance_summary' => $balance['balance_summary'],
        ];
    }

    protected function buildTeamBlankLevelData(int $parentId): array
    {
        $parent = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'wallet_id',
                'referral_id',
                'referral_code',
                'nickname',
                'leader_nickname',
                'created_at',
                'deactivated',
                'email_verified_at',
                'kyc_verified_at',
                'is_t',
            ])
            ->where('id', $parentId)
            ->first();

        if (!$parent) {
            return [
                'parent' => null,
                'children' => [],
                'summary' => $this->emptyTeamSummary(),
            ];
        }

        $children = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'wallet_id',
                'referral_id',
                'referral_code',
                'nickname',
                'leader_nickname',
                'created_at',
                'deactivated',
                'email_verified_at',
                'kyc_verified_at',
                'is_t',
            ])
            ->where('referral_id', $parentId)
            ->orderBy('id', 'asc')
            ->get();

        $childrenData = $children->map(function (User $child) {
            return $this->formatTeamBlankNode($child);
        })->values()->toArray();

        return [
            'parent' => $this->formatTeamBlankNode($parent),
            'children' => $childrenData,
            'summary' => $this->buildTeamBlankSummary($childrenData),
        ];
    }

    protected function formatTeamBlankNode(User $user): array
    {
        $finance = $this->getTeamUserFinanceSummary((int) $user->id);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'wallet_id' => $user->wallet_id,
            'referral_id' => $user->referral_id,
            'referral_code' => $user->referral_code,
            'nickname' => trim((string) ($user->nickname ?? '')),
            'leader_nickname' => trim((string) ($user->leader_nickname ?? '')),
            'display_name' => $this->formatUserDisplayName($user),
            'created_at' => $user->created_at ? Carbon::parse($user->created_at)->format('Y-m-d H:i:s') : '',
            'deactivated' => (bool) $user->deactivated,
            'email_verified_at' => $user->email_verified_at,
            'kyc_verified_at' => $user->kyc_verified_at,
            'is_t' => (bool) $user->is_t,
            'has_children' => $this->hasTeamChildren((int) $user->id),

            'total_deposit' => $finance['total_deposit'],
            'total_withdrawal' => $finance['total_withdrawal'],
            'deposit_withdrawal_diff' => $finance['deposit_withdrawal_diff'],
            'wallet_balance' => $finance['wallet_balance'],
            'trade_balance' => $finance['trade_balance'],

            'account_balance' => $finance['account_balance'],
            'latest_deposit' => $finance['latest_deposit'],
            'latest_deposit_time' => $finance['latest_deposit_time'],
        ];
    }

    protected function getTeamUserFinanceSummary(int $userId): array
    {
        /*
         * 团队页每一行显示该用户整棵下级树的数据：
         * 当前用户 + 所有层级下级。
         * 如果中间上级是虚拟账户，不会断层；最终金额统计时跳过 is_xn = true 的用户。
         */
        $teamUserIds = $this->getAllTeamUserIds($userId);

        if (empty($teamUserIds)) {
            $teamUserIds = [$userId];
        }

        $totalDeposit = '0';
        $totalWithdrawal = '0';
        $walletBalance = '0';
        $tradeBalance = '0';
        $latestDeposit = [
            'amount' => 0,
            'created_at' => '',
        ];

        foreach ($teamUserIds as $teamMemberId) {
            $teamMemberId = (int) $teamMemberId;

            if ($this->isVirtualUser($teamMemberId)) {
                continue;
            }

            $totalDeposit = math_sum(
                $totalDeposit,
                $this->sumUserTableAmount('deposits', $teamMemberId)
            );

            $totalWithdrawal = math_sum(
                $totalWithdrawal,
                $this->sumUserTableAmount('withdrawals', $teamMemberId)
            );

            $walletSummary = $this->getUserWalletBalanceTradeForTeamPage($teamMemberId);

            $walletBalance = math_sum($walletBalance, $walletSummary['wallet_balance'] ?? 0);
            $tradeBalance = math_sum($tradeBalance, $walletSummary['trade_balance'] ?? 0);

            $memberLatestDeposit = $this->getLatestUserAmountRow('deposits', $teamMemberId);

            if (!empty($memberLatestDeposit['created_at'])) {
                if (empty($latestDeposit['created_at'])) {
                    $latestDeposit = $memberLatestDeposit;
                } else {
                    try {
                        if (Carbon::parse($memberLatestDeposit['created_at'])->gt(Carbon::parse($latestDeposit['created_at']))) {
                            $latestDeposit = $memberLatestDeposit;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        $depositWithdrawalDiff = math_sub($totalDeposit, $totalWithdrawal);
        $accountBalance = math_sum($walletBalance, $tradeBalance);

        return [
            'total_deposit' => $this->formatTeamMoney($totalDeposit),
            'total_withdrawal' => $this->formatTeamMoney($totalWithdrawal),
            'deposit_withdrawal_diff' => $this->formatTeamMoney($depositWithdrawalDiff),

            'wallet_balance' => $this->formatTeamMoney($walletBalance),
            'trade_balance' => $this->formatTeamMoney($tradeBalance),
            'account_balance' => $this->formatTeamMoney($accountBalance),

            'latest_deposit' => $this->formatTeamMoney($latestDeposit['amount']),
            'latest_deposit_time' => $latestDeposit['created_at'],
        ];
    }

    protected function buildTeamBlankSummary(array $children): array
    {
        $totalDeposit = 0;
        $totalWithdrawal = 0;
        $walletBalance = 0;
        $tradeBalance = 0;

        foreach ($children as $child) {
            $totalDeposit = math_sum($totalDeposit, $this->cleanMoneyNumber($child['total_deposit'] ?? 0));
            $totalWithdrawal = math_sum($totalWithdrawal, $this->cleanMoneyNumber($child['total_withdrawal'] ?? 0));
            $walletBalance = math_sum($walletBalance, $this->cleanMoneyNumber($child['wallet_balance'] ?? 0));
            $tradeBalance = math_sum($tradeBalance, $this->cleanMoneyNumber($child['trade_balance'] ?? 0));
        }

        return [
            'total_deposit' => $this->formatTeamMoney($totalDeposit),
            'total_withdrawal' => $this->formatTeamMoney($totalWithdrawal),
            'wallet_balance' => $this->formatTeamMoney($walletBalance),
            'trade_balance' => $this->formatTeamMoney($tradeBalance),

            'account_balance' => $this->formatTeamMoney(math_sum($walletBalance, $tradeBalance)),
            'deposit_withdrawal_diff' => $this->formatTeamMoney(math_sub($totalDeposit, $totalWithdrawal)),
        ];
    }

    protected function emptyTeamSummary(): array
    {
        return [
            'total_deposit' => '0',
            'total_withdrawal' => '0',
            'wallet_balance' => '0',
            'trade_balance' => '0',
            'account_balance' => '0',
            'deposit_withdrawal_diff' => '0',
        ];
    }

protected function sumUserTableAmount(string $table, int $userId)
    {
        if ($this->isVirtualUser($userId)) {
            return 0;
        }

        if (!Schema::hasTable($table)) {
            return 0;
        }

        if (!Schema::hasColumn($table, 'user_id')) {
            return 0;
        }

        $amountColumn = $this->resolveAmountColumn($table);

        if (!$amountColumn) {
            return 0;
        }

        $query = DB::table($table)->where('user_id', $userId);

        $this->applyInternalTransferDepositScope($table, $query);

        if (Schema::hasColumn($table, 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', [
                        'rejected',
                        'failed',
                        'cancelled',
                        'canceled',
                        'error',
                        'pending',
                    ]);
            });
        }

        /*
         * 如果表里有 currency_id，则不能直接 sum(amount)。
         *
         * 入金记录里的 usdt_rate 是客户充值时保存下来的历史汇率。
         * 所以 deposits 表必须按照每条充值记录自己的 usdt_rate 折算，
         * 不能再使用 markets.last 这种实时价格。
         */
        if (Schema::hasColumn($table, 'currency_id')) {
            $hasRecordUsdtRate = Schema::hasColumn($table, 'usdt_rate');

            $selectColumns = [
                'currency_id',
                $amountColumn . ' as amount_value',
            ];

            if ($hasRecordUsdtRate) {
                $selectColumns[] = 'usdt_rate';
            }

            $rows = $query
                ->select($selectColumns)
                ->get();

            $totalUsdtAmount = '0';

            foreach ($rows as $row) {
                $usdtAmount = $this->convertAmountToUsdt(
                    $row->amount_value ?? 0,
                    (int) ($row->currency_id ?? 0),
                    $hasRecordUsdtRate ? ($row->usdt_rate ?? null) : null,
                    !$hasRecordUsdtRate
                );

                $totalUsdtAmount = math_sum($totalUsdtAmount, $usdtAmount);
            }

            return $totalUsdtAmount;
        }

        return $query->sum($amountColumn);
    }

protected function getLatestUserAmountRow(string $table, int $userId): array
    {
        if ($this->isVirtualUser($userId)) {
            return [
                'amount' => 0,
                'created_at' => '',
            ];
        }

        if (!Schema::hasTable($table)) {
            return [
                'amount' => 0,
                'created_at' => '',
            ];
        }

        if (!Schema::hasColumn($table, 'user_id')) {
            return [
                'amount' => 0,
                'created_at' => '',
            ];
        }

        $amountColumn = $this->resolveAmountColumn($table);

        if (!$amountColumn) {
            return [
                'amount' => 0,
                'created_at' => '',
            ];
        }

        $dateColumn = $this->resolveDateColumn($table);

        $selectColumns = [$amountColumn . ' as amount_value'];

        if ($dateColumn) {
            $selectColumns[] = $dateColumn;
        }

        if (Schema::hasColumn($table, 'currency_id')) {
            $selectColumns[] = 'currency_id';
        }

        $hasRecordUsdtRate = Schema::hasColumn($table, 'usdt_rate');

        if ($hasRecordUsdtRate) {
            $selectColumns[] = 'usdt_rate';
        }

        $query = DB::table($table)
            ->select($selectColumns)
            ->where('user_id', $userId);

        $this->applyInternalTransferDepositScope($table, $query);

        if (Schema::hasColumn($table, 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', [
                        'rejected',
                        'failed',
                        'cancelled',
                        'canceled',
                        'error',
                        'pending',
                    ]);
            });
        }

        if ($dateColumn) {
            $query->orderByDesc($dateColumn);
        } else {
            $query->orderByDesc('id');
        }

        $row = $query->first();

        if (!$row) {
            return [
                'amount' => 0,
                'created_at' => '',
            ];
        }

        $createdAt = '';

        if ($dateColumn && !empty($row->{$dateColumn})) {
            try {
                $createdAt = Carbon::parse($row->{$dateColumn})->format('m/d/Y H:i:s');
            } catch (\Throwable $e) {
                $createdAt = (string) $row->{$dateColumn};
            }
        }

        $amount = $row->amount_value ?? 0;

        if (Schema::hasColumn($table, 'currency_id')) {
            $amount = $this->convertAmountToUsdt(
                $amount,
                (int) ($row->currency_id ?? 0),
                $hasRecordUsdtRate ? ($row->usdt_rate ?? null) : null,
                !$hasRecordUsdtRate
            );
        }

        return [
            'amount' => $amount,
            'created_at' => $createdAt,
        ];
    }

protected function applyInternalTransferDepositScope(string $table, $query): void
    {
        if ($table !== 'deposits' || !Schema::hasColumn('deposits', 'source_id')) {
            return;
        }

        $query->where(function ($q) {
            $q->whereNull('source_id')
                ->orWhereNotIn('source_id', [
                    DepositRepository::ADMIN_INTERNAL_TRANSFER_SOURCE,
                    DepositRepository::PLATFORM_INTERNAL_TRANSFER_SOURCE,
                ]);
        });
    }

protected function getUserWalletBalanceTradeForTeamPage(int $userId): array
    {
        if ($this->isVirtualUser($userId)) {
            return [
                'wallet_balance' => 0,
                'trade_balance' => 0,
                'total_balance' => 0,
            ];
        }

        if (!Schema::hasTable('wallets')) {
            return [
                'wallet_balance' => 0,
                'trade_balance' => 0,
                'total_balance' => 0,
            ];
        }

        $walletFields = [];
        $tradeFields = [];

        if (Schema::hasColumn('wallets', 'balance_in_wallet')) {
            $walletFields[] = 'COALESCE(balance_in_wallet, 0)';
        }

        /*
         * 团队页资金数据只统计真实账户。
         * 不再把 balance_in_virtual_wallet / balance_in_virtual_trade 加进去。
         */
        if (Schema::hasColumn('wallets', 'balance_in_trade')) {
            $tradeFields[] = 'COALESCE(balance_in_trade, 0)';
        }

        $walletExpr = !empty($walletFields) ? implode(' + ', $walletFields) : '0';
        $tradeExpr = !empty($tradeFields) ? implode(' + ', $tradeFields) : '0';

        $rows = DB::table('wallets')
            ->where('user_id', $userId)
            ->selectRaw("currency_id, SUM({$walletExpr}) as wallet_balance, SUM({$tradeExpr}) as trade_balance")
            ->groupBy('currency_id')
            ->get();

        $walletBalance = '0';
        $tradeBalance = '0';

        foreach ($rows as $row) {
            $walletUsdt = $this->convertAmountToUsdt(
                $row->wallet_balance ?? 0,
                (int) ($row->currency_id ?? 0)
            );

            $tradeUsdt = $this->convertAmountToUsdt(
                $row->trade_balance ?? 0,
                (int) ($row->currency_id ?? 0)
            );

            $walletBalance = math_sum($walletBalance, $walletUsdt);
            $tradeBalance = math_sum($tradeBalance, $tradeUsdt);
        }

        return [
            'wallet_balance' => $walletBalance,
            'trade_balance' => $tradeBalance,
            'total_balance' => math_sum($walletBalance, $tradeBalance),
        ];
    }

    protected function getUserWalletTotalBalanceForTeamPage(int $userId)
    {
        $summary = $this->getUserWalletBalanceTradeForTeamPage($userId);

        return $summary['total_balance'] ?? 0;
    }

    protected function getUserRealAutoInvestBalanceForList(int $userId): string
    {
        if ($this->isVirtualUser($userId)) {
            return '0';
        }

        if (!Schema::hasTable('auto_invest_orders')) {
            return '0';
        }

        if (
            !Schema::hasColumn('auto_invest_orders', 'user_id') ||
            !Schema::hasColumn('auto_invest_orders', 'currency_id') ||
            !Schema::hasColumn('auto_invest_orders', 'amount')
        ) {
            return '0';
        }

        $selectColumns = [
            'currency_id',
            'amount',
        ];

        $hasMeta = Schema::hasColumn('auto_invest_orders', 'meta');

        if ($hasMeta) {
            $selectColumns[] = 'meta';
        }

        $query = DB::table('auto_invest_orders')
            ->select($selectColumns)
            ->where('user_id', $userId);

        if (Schema::hasColumn('auto_invest_orders', 'status')) {
            $query->where('status', 'active');
        }

        $rows = $query->get();
        $total = '0';

        foreach ($rows as $row) {
            if ($hasMeta && $this->isVirtualSourceMeta($row->meta ?? null)) {
                continue;
            }

            $total = math_sum(
                $total,
                $this->convertAmountToUsdt($row->amount ?? 0, (int) ($row->currency_id ?? 0))
            );
        }

        return $total;
    }

    protected function getUserRealStakingBalanceForList(int $userId): string
    {
        if ($this->isVirtualUser($userId)) {
            return '0';
        }

        if (!Schema::hasTable('staking_users')) {
            return '0';
        }

        if (
            !Schema::hasColumn('staking_users', 'user_id') ||
            !Schema::hasColumn('staking_users', 'amount')
        ) {
            return '0';
        }

        $selectColumns = ['amount'];
        $hasCurrencyId = Schema::hasColumn('staking_users', 'currency_id');
        $hasStakingId = Schema::hasColumn('staking_users', 'staking_id');
        $hasMeta = Schema::hasColumn('staking_users', 'meta');

        if ($hasCurrencyId) {
            $selectColumns[] = 'currency_id';
        }

        if ($hasStakingId) {
            $selectColumns[] = 'staking_id';
        }

        if ($hasMeta) {
            $selectColumns[] = 'meta';
        }

        $query = DB::table('staking_users')
            ->select($selectColumns)
            ->where('user_id', $userId);

        if (Schema::hasColumn('staking_users', 'status')) {
            $query->where('status', 'active');
        }

        $rows = $query->get();
        $total = '0';

        foreach ($rows as $row) {
            if ($hasMeta && $this->isVirtualSourceMeta($row->meta ?? null)) {
                continue;
            }

            $currencyId = $hasCurrencyId ? (int) ($row->currency_id ?? 0) : 0;

            if ($currencyId <= 0 && $hasStakingId) {
                $currencyId = $this->getStakingProductCurrencyId((int) ($row->staking_id ?? 0));
            }

            $total = math_sum(
                $total,
                $this->convertAmountToUsdt($row->amount ?? 0, $currencyId)
            );
        }

        return $total;
    }

    protected function getStakingProductCurrencyId(int $stakingId): int
    {
        if ($stakingId <= 0 || !$this->schemaHasTableCached('staking')) {
            return 0;
        }

        if (!$this->schemaHasColumnCached('staking', 'currency_id')) {
            return 0;
        }

        if (!array_key_exists($stakingId, $this->stakingProductCurrencyIdCache)) {
            $this->stakingProductCurrencyIdCache[$stakingId] = (int) DB::table('staking')
                ->where('id', $stakingId)
                ->value('currency_id');
        }

        return $this->stakingProductCurrencyIdCache[$stakingId];
    }

    protected function isVirtualSourceMeta($meta): bool
    {
        if (empty($meta)) {
            return false;
        }

        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($meta)) {
            return false;
        }

        $sourceAccountType = $meta['source_account_type'] ?? null;
        $sourceBalanceField = $meta['source_balance_field'] ?? ($meta['source_field'] ?? null);

        return $sourceAccountType === 'virtual' ||
            $sourceBalanceField === 'balance_in_virtual_trade' ||
            $sourceBalanceField === 'balance_in_virtual_wallet';
    }

	protected function isVirtualUser(int $userId): bool
	    {
        if (!$this->schemaHasTableCached('users') || !$this->schemaHasColumnCached('users', 'is_xn')) {
            return false;
        }

        if (!array_key_exists($userId, $this->virtualUserCache)) {
            $this->virtualUserCache[$userId] = DB::table('users')
                ->where('id', $userId)
                ->where('is_xn', true)
                ->exists();
        }

        return $this->virtualUserCache[$userId];
    }

    protected function convertAmountToUsdt($amount, int $currencyId, $recordUsdtRate = null, bool $allowMarketFallback = true): string
    {
        if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0) {
            return '0';
        }

        if (!$this->schemaHasTableCached('currencies')) {
            return '0';
        }

        $currencySymbol = $this->getCurrencySymbolCached($currencyId);

        if (empty($currencySymbol)) {
            return '0';
        }

        $symbol = strtoupper(trim((string) $currencySymbol));

        /*
         * USDT / USDC / USD 以及常见美元稳定币，直接按 1:1 处理。
         */
        if ($this->isUsdStableCurrencySymbol($symbol)) {
            return math_formatter($amount, 8, '.', '');
        }

        /*
         * 非稳定币入金必须使用客户充值记录里保存的 usdt_rate。
         * 这里不会偷偷读取实时行情。
         */
        if (is_numeric($recordUsdtRate) && (float) $recordUsdtRate > 0) {
            return math_formatter(
                math_multiply($amount, $recordUsdtRate),
                8,
                '.',
                ''
            );
        }

        /*
         * 对 deposits 这类带 usdt_rate 的表，如果该条历史记录没有 usdt_rate，
         * 就返回 0，不再用 markets.last，避免变成实时价格。
         *
         * 只有钱包余额这类没有订单历史汇率的地方，才允许 fallback 到 markets.last。
         */
        if (!$allowMarketFallback) {
            return '0';
        }

        $rate = $this->getCurrencyToUsdtRate($symbol, $currencyId);

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

    protected function isUsdStableCurrencySymbol(string $symbol): bool
    {
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '') {
            return false;
        }

        /*
         * 兼容 USDC-ERC20 / USDC_TRC20 / USDC.E 这类写法。
         */
        $normalizedSymbol = str_replace(['-', '_', '.', ' '], '', $symbol);

        $stableSymbols = [
            'USD',
            'USDT',
            'USDC',
            'USDCERC20',
            'USDCTRC20',
            'BUSD',
            'FDUSD',
            'TUSD',
            'USDP',
            'DAI',
        ];

        if (in_array($normalizedSymbol, $stableSymbols, true)) {
            return true;
        }

        return str_starts_with($normalizedSymbol, 'USDC');
    }

    protected function getUsdQuoteCurrencyIds(): array
    {
        if ($this->quoteCurrencyIdsCache !== null) {
            return $this->quoteCurrencyIdsCache;
        }

        $usdt = DB::table('currencies')
            ->select(['id', 'symbol'])
            ->whereRaw('UPPER(symbol) = ?', ['USDT'])
            ->first();

        $usd = DB::table('currencies')
            ->select(['id', 'symbol'])
            ->whereRaw('UPPER(symbol) = ?', ['USD'])
            ->first();

        $quoteIds = [];
        $usdtId = null;

        if ($usdt) {
            $usdtId = (int) $usdt->id;
            $quoteIds[] = $usdtId;
        }

        if ($usd && !in_array((int) $usd->id, $quoteIds, true)) {
            $quoteIds[] = (int) $usd->id;
        }

        $this->quoteCurrencyIdsCache = [
            'ids' => $quoteIds,
            'usdt' => $usdtId,
        ];

        return $this->quoteCurrencyIdsCache;
    }

    protected function getCurrencyToUsdtRate(string $symbol, ?int $currencyId = null): string
    {
        $symbol = strtoupper(trim($symbol));
        $currencyId = (int) ($currencyId ?? 0);

        if ($symbol === '') {
            return '0';
        }

        if ($this->isUsdStableCurrencySymbol($symbol)) {
            return '1';
        }

        $cacheKey = $currencyId > 0 ? 'id:' . $currencyId : 'symbol:' . $symbol;

        if (isset($this->currencyRateCache[$cacheKey])) {
            return $this->currencyRateCache[$cacheKey];
        }

        /*
         * 注意：
         * usdt_rate 记录在客户充值记录里面，不在 currencies 表里面。
         * 本方法只用于钱包余额等没有订单历史汇率的场景，作为实时行情兜底。
         * 入金总额不会走到这里的实时价格。
         */
        if (!$this->schemaHasTableCached('currencies') || !$this->schemaHasTableCached('markets')) {
            $this->currencyRateCache[$cacheKey] = '0';
            return '0';
        }

        if (
            !$this->schemaHasColumnCached('markets', 'base_currency_id') ||
            !$this->schemaHasColumnCached('markets', 'quote_currency_id') ||
            !$this->schemaHasColumnCached('markets', 'last')
        ) {
            $this->currencyRateCache[$cacheKey] = '0';
            return '0';
        }

        $currencyQuery = DB::table('currencies')
            ->select(['id', 'symbol']);

        if ($currencyId > 0) {
            $currencyQuery->where('id', $currencyId);
        } else {
            $currencyQuery->whereRaw('UPPER(symbol) = ?', [$symbol]);
        }

        $currency = $currencyQuery->first();

        if (!$currency) {
            $this->currencyRateCache[$cacheKey] = '0';
            return '0';
        }

        $quoteIds = $this->getUsdQuoteCurrencyIds();
        $usdtId = $quoteIds['usdt'] ?? null;
        $quoteIds = $quoteIds['ids'] ?? [];

        if (empty($quoteIds)) {
            $this->currencyRateCache[$cacheKey] = '0';
            return '0';
        }

        $directQuery = DB::table('markets')
            ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
            ->where('base_currency_id', (int) $currency->id)
            ->whereIn('quote_currency_id', $quoteIds)
            ->whereNotNull('last')
            ->where('last', '>', 0);

        if ($usdtId) {
            $directQuery->orderByRaw('CASE WHEN quote_currency_id = ' . (int) $usdtId . ' THEN 0 ELSE 1 END');
        }

        $directMarket = $directQuery->first();

        if ($directMarket && is_numeric($directMarket->last) && (float) $directMarket->last > 0) {
            $this->currencyRateCache[$cacheKey] = math_formatter($directMarket->last, 18, '.', '');
            return $this->currencyRateCache[$cacheKey];
        }

        $reverseQuery = DB::table('markets')
            ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
            ->whereIn('base_currency_id', $quoteIds)
            ->where('quote_currency_id', (int) $currency->id)
            ->whereNotNull('last')
            ->where('last', '>', 0);

        if ($usdtId) {
            $reverseQuery->orderByRaw('CASE WHEN base_currency_id = ' . (int) $usdtId . ' THEN 0 ELSE 1 END');
        }

        $reverseMarket = $reverseQuery->first();

        if ($reverseMarket && is_numeric($reverseMarket->last) && (float) $reverseMarket->last > 0) {
            $this->currencyRateCache[$cacheKey] = math_formatter(
                math_divide(1, $reverseMarket->last),
                18,
                '.',
                ''
            );

            return $this->currencyRateCache[$cacheKey];
        }

        $this->currencyRateCache[$cacheKey] = '0';

        return '0';
    }

        protected function resolveAmountColumn(string $table): ?string
    {
        $columns = [
            'amount',
            'quantity',
            'total',
            'value',
            'received_amount',
            'final_amount',
        ];

        foreach ($columns as $column) {
            if ($this->schemaHasColumnCached($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    protected function resolveDateColumn(string $table): ?string
    {
        $columns = [
            'created_at',
            'updated_at',
            'confirmed_at',
            'processed_at',
            'created',
        ];

        foreach ($columns as $column) {
            if ($this->schemaHasColumnCached($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    protected function cleanMoneyNumber($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = str_replace(',', '', (string) $value);

        if (!is_numeric($value)) {
            return 0;
        }

        return $value;
    }

    protected function formatTeamMoney($value): string
    {
        $value = $this->cleanMoneyNumber($value);

        $formatted = math_formatter($value, 2);

        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }

    protected function appendBalanceSummaryToUser($user)
    {
        $balance = $this->buildUserBalanceSummary((int) $user->id);

        foreach ($balance as $key => $value) {
            $user->{$key} = $value;
        }

        return $user;
    }

protected function buildUserBalanceSummary(int $userId): array
    {
        if ($this->isVirtualUser($userId)) {
            $zero = $this->formatAdminBalance(0);

            return [
                'wallet_balance' => $zero,
                'trade_balance' => $zero,
                'total_balance' => $zero,

                'real_wallet_balance' => $zero,
                'real_trade_balance' => $zero,
                'real_order_balance' => $zero,
                'real_withdraw_balance' => $zero,
                'real_total_balance' => $zero,
                'real_locked_total_balance' => $zero,

                'virtual_wallet_balance' => $zero,
                'virtual_trade_balance' => $zero,
                'virtual_order_balance' => $zero,
                'virtual_total_balance' => $zero,
                'virtual_locked_total_balance' => $zero,

                'grand_total_balance' => $zero,
                'locked_total_balance' => $zero,

                'balance_summary' => [
                    'real' => [
                        'wallet' => $zero,
                        'trade' => $zero,
                        'order' => $zero,
                        'withdraw' => $zero,
                        'total' => $zero,
                        'locked_total' => $zero,
                    ],
                    'virtual' => [
                        'wallet' => $zero,
                        'trade' => $zero,
                        'order' => $zero,
                        'total' => $zero,
                        'locked_total' => $zero,
                    ],
                    'total' => [
                        'available' => $zero,
                        'locked' => $zero,
                    ],
                ],
            ];
        }

        $walletRepository = new \App\Repositories\Wallet\WalletRepository();
        $wallets = $walletRepository->getWallets($userId);

        $realWallet = '0';
        $realTrade = '0';
        $realOrder = '0';
        $realWithdraw = '0';

        /*
         * 虚拟账户资金不参与当前页面展示与统计。
         * 这里保留字段，但统一返回 0。
         */
        $virtualWallet = '0';
        $virtualTrade = '0';
        $virtualOrder = '0';

        foreach ($wallets as $wallet) {
            $currencyId = (int) ($wallet->currency_id ?? 0);

            $realWallet = math_sum(
                $realWallet,
                $this->convertAmountToUsdt($wallet->balance_in_wallet ?? 0, $currencyId)
            );

            $realTrade = math_sum(
                $realTrade,
                $this->convertAmountToUsdt($wallet->balance_in_trade ?? 0, $currencyId)
            );

            $realOrder = math_sum(
                $realOrder,
                $this->convertAmountToUsdt($wallet->balance_in_order ?? 0, $currencyId)
            );

            $realWithdraw = math_sum(
                $realWithdraw,
                $this->convertAmountToUsdt($wallet->balance_in_withdraw ?? 0, $currencyId)
            );
        }

        $realTotal = math_sum($realWallet, $realTrade);
        $virtualTotal = math_sum($virtualWallet, $virtualTrade);
        $grandTotal = $realTotal;

        $realLockedTotal = math_sum($realOrder, $realWithdraw);
        $virtualLockedTotal = $virtualOrder;
        $lockedTotal = $realLockedTotal;

        return [
            'wallet_balance' => $this->formatAdminBalance($realWallet),
            'trade_balance' => $this->formatAdminBalance($realTrade),
            'total_balance' => $this->formatAdminBalance($grandTotal),

            'real_wallet_balance' => $this->formatAdminBalance($realWallet),
            'real_trade_balance' => $this->formatAdminBalance($realTrade),
            'real_order_balance' => $this->formatAdminBalance($realOrder),
            'real_withdraw_balance' => $this->formatAdminBalance($realWithdraw),
            'real_total_balance' => $this->formatAdminBalance($realTotal),
            'real_locked_total_balance' => $this->formatAdminBalance($realLockedTotal),

            'virtual_wallet_balance' => $this->formatAdminBalance($virtualWallet),
            'virtual_trade_balance' => $this->formatAdminBalance($virtualTrade),
            'virtual_order_balance' => $this->formatAdminBalance($virtualOrder),
            'virtual_total_balance' => $this->formatAdminBalance($virtualTotal),
            'virtual_locked_total_balance' => $this->formatAdminBalance($virtualLockedTotal),

            'grand_total_balance' => $this->formatAdminBalance($grandTotal),
            'locked_total_balance' => $this->formatAdminBalance($lockedTotal),

            'balance_summary' => [
                'real' => [
                    'wallet' => $this->formatAdminBalance($realWallet),
                    'trade' => $this->formatAdminBalance($realTrade),
                    'order' => $this->formatAdminBalance($realOrder),
                    'withdraw' => $this->formatAdminBalance($realWithdraw),
                    'total' => $this->formatAdminBalance($realTotal),
                    'locked_total' => $this->formatAdminBalance($realLockedTotal),
                ],
                'virtual' => [
                    'wallet' => $this->formatAdminBalance($virtualWallet),
                    'trade' => $this->formatAdminBalance($virtualTrade),
                    'order' => $this->formatAdminBalance($virtualOrder),
                    'total' => $this->formatAdminBalance($virtualTotal),
                    'locked_total' => $this->formatAdminBalance($virtualLockedTotal),
                ],
                'total' => [
                    'available' => $this->formatAdminBalance($grandTotal),
                    'locked' => $this->formatAdminBalance($lockedTotal),
                ],
            ],
        ];
    }

    protected function formatAdminBalance($value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            $value = 0;
        }

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }

        if (!is_numeric($value)) {
            $value = 0;
        }

        return math_formatter($value, $decimals);
    }

protected function walletBalanceSqlExpression(string $type = 'total'): string
    {
        $realWallet = 'COALESCE(wallets.balance_in_wallet, 0)';
        $realTrade = 'COALESCE(wallets.balance_in_trade, 0)';

        $virtualWallet = Schema::hasColumn('wallets', 'balance_in_virtual_wallet')
            ? 'COALESCE(wallets.balance_in_virtual_wallet, 0)'
            : '0';

        $virtualTrade = Schema::hasColumn('wallets', 'balance_in_virtual_trade')
            ? 'COALESCE(wallets.balance_in_virtual_trade, 0)'
            : '0';

        /*
         * 当前用户页统计默认只统计真实账户资金。
         * 只有显式传 virtual 时，才返回虚拟字段。
         */
        if ($type === 'virtual') {
            return "{$virtualWallet} + {$virtualTrade}";
        }

        if ($type === 'account' || $type === 'wallet') {
            return "{$realWallet}";
        }

        if ($type === 'trade') {
            return "{$realTrade}";
        }

        return "{$realWallet} + {$realTrade}";
    }

    protected function hasTeamChildren(int $userId): bool
    {
        return User::query()
            ->where('referral_id', $userId)
            ->exists();
    }

    protected function formatUserDisplayName(User $user): string
    {
        $nickname = trim((string) ($user->nickname ?? ''));

        if ($nickname !== '') {
            return $nickname;
        }

        $email = trim((string) ($user->email ?? ''));

        if ($email !== '') {
            return $email;
        }

        $phone = trim((string) ($user->phone ?? ''));

        if ($phone !== '') {
            return $phone;
        }

        $walletId = trim((string) ($user->wallet_id ?? ''));

        if ($walletId !== '') {
            return $walletId;
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return 'UID ' . $user->id;
    }

    protected function buildUserReferrerChain(User $user, array &$userCache): array
    {
        $chain = [];
        $visited = [];
        $referralId = $user->referral_id ?? null;
        $level = 1;

        while ($referralId && $level <= 50) {
            $referralId = (int) $referralId;

            if ($referralId <= 0 || in_array($referralId, $visited, true)) {
                break;
            }

            $visited[] = $referralId;
            $parent = $this->getUserReferrerFromCache($referralId, $userCache);

            if (!$parent) {
                break;
            }

            $chain[] = [
                'level' => $level,
                'id' => (int) $parent->id,
                'account' => $this->formatUserReferrerAccount($parent),
                'nickname' => $this->formatUserReferrerNickname($parent),
                'name' => $this->formatUserReferrerName($parent),
                'referral_code' => $parent->referral_code,
                'leader_nickname' => $parent->leader_nickname,
            ];

            $referralId = $parent->referral_id ?? null;
            $level++;
        }

        return $chain;
    }

    protected function getUserReferrerFromCache(int $userId, array &$userCache): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        if (!array_key_exists($userId, $userCache)) {
            $userCache[$userId] = User::query()
                ->with('roles')
                ->select([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'wallet_id',
                    'referral_id',
                    'referral_code',
                    'nickname',
                    'leader_nickname',
                ])
                ->where('id', $userId)
                ->first();
        }

        return $userCache[$userId];
    }

    protected function resolveUserReferrerDisplayName(array $chain): string
    {
        if (empty($chain)) {
            return 'N/A';
        }

        $direct = $chain[0];

        foreach (['nickname', 'account', 'name', 'referral_code'] as $field) {
            $value = trim((string) ($direct[$field] ?? ''));

            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return !empty($direct['id']) ? 'ID: ' . $direct['id'] : 'N/A';
    }

    protected function formatUserReferrerAccount(User $user): string
    {
        foreach (['email', 'phone', 'wallet_id', 'referral_code'] as $field) {
            $value = trim((string) ($user->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return 'ID: ' . $user->id;
    }

    protected function formatUserReferrerNickname(User $user): string
    {
        $nickname = trim((string) ($user->nickname ?? ''));

        return $nickname !== '' ? $nickname : '-';
    }

    protected function formatUserReferrerName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : $this->formatUserReferrerAccount($user);
    }

    protected function resolveLeaderDisplayNameForList(User $user): string
    {
        $user->loadMissing('roles');

        $leaderNickname = trim((string) ($user->leader_nickname ?? ''));
        $nickname = trim((string) ($user->nickname ?? ''));

        if ($user->hasRole('user_leader')) {
            return $leaderNickname !== '' ? $leaderNickname : $nickname;
        }

        $leader = $this->findNearestLeader($user);

        if (!$leader) {
            return '';
        }

        return $this->formatLeaderName($leader);
    }

    protected function findNearestLeader(User $user): ?User
    {
        $referralId = $user->referral_id ?? null;
        $visited = [];
        $depth = 0;

        while ($referralId && $depth < 50) {
            $referralId = (int) $referralId;

            if (in_array($referralId, $visited, true)) {
                break;
            }

            $visited[] = $referralId;

            $parent = User::query()
                ->with('roles')
                ->select([
                    'id',
                    'name',
                    'email',
                    'wallet_id',
                    'referral_id',
                    'nickname',
                    'leader_nickname',
                ])
                ->where('id', $referralId)
                ->first();

            if (!$parent) {
                break;
            }

            if ($parent->hasRole('user_leader')) {
                return $parent;
            }

            $referralId = $parent->referral_id ?? null;
            $depth++;
        }

        return null;
    }

    protected function formatLeaderName(User $leader): string
    {
        $leaderNickname = trim((string) ($leader->leader_nickname ?? ''));

        if ($leaderNickname !== '') {
            return $leaderNickname;
        }

        $nickname = trim((string) ($leader->nickname ?? ''));

        if ($nickname !== '') {
            return $nickname;
        }

        return '';
    }


public function getAllUserBalances($type = 'total')
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [
                'totalUsdBalance' => 0,
                'totalBtcBalance' => 0,
            ];
        }

        $currentUser->loadMissing('roles');

        $roleNames = $currentUser->roles->pluck('name')->filter()->values()->toArray();
        $roleIds = $currentUser->roles->pluck('id')->map(function ($id) {
            return (int) $id;
        })->toArray();

        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        $hasTeamDataScope = count(array_intersect($roleNames, $teamScopeRoles)) > 0 || (auth()->user()?->hasRole('admin') ?? false);

        $teamUserId = request()->get('team_user_id');

        /*
         * 用户页顶部余额统计：
         * 1. 只统计真实账户用户，排除 users.is_xn = true。
         * 2. 只统计真实余额字段，默认不统计虚拟余额字段。
         * 3. 所有币种按 markets.last 折算成 USDT。
         */
        $walletQuery = Wallet::query()
            ->join('users', 'wallets.user_id', '=', 'users.id')
            ->where('users.is_t', false)
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if ($isSuperAdmin) {
            if (!empty($teamUserId)) {
                $teamUserIds = array_map('intval', $this->getAllTeamUserIds((int) $teamUserId));

                if (empty($teamUserIds)) {
                    return [
                        'totalUsdBalance' => 0,
                        'totalBtcBalance' => 0,
                    ];
                }

                $walletQuery->whereIn('wallets.user_id', $teamUserIds);
            }
        } elseif ($hasTeamDataScope) {
            if (!empty($teamUserId)) {
                $myTeamUserIds = array_map('intval', $this->getAllTeamUserIds((int) $currentUser->id));

                if (!in_array((int) $teamUserId, $myTeamUserIds, true)) {
                    return [
                        'totalUsdBalance' => 0,
                        'totalBtcBalance' => 0,
                    ];
                }

                $teamUserIds = array_map('intval', $this->getAllTeamUserIds((int) $teamUserId));

                if (empty($teamUserIds)) {
                    return [
                        'totalUsdBalance' => 0,
                        'totalBtcBalance' => 0,
                    ];
                }

                $walletQuery->whereIn('wallets.user_id', $teamUserIds);
            } else {
                $teamUserIds = array_map('intval', $this->getAllTeamUserIds((int) $currentUser->id));

                if (empty($teamUserIds)) {
                    return [
                        'totalUsdBalance' => 0,
                        'totalBtcBalance' => 0,
                    ];
                }

                $walletQuery->whereIn('wallets.user_id', $teamUserIds);
            }
        } else {
            return [
                'totalUsdBalance' => 0,
                'totalBtcBalance' => 0,
            ];
        }

        $balanceSql = $this->walletBalanceSqlExpression($type);

        $wallets = $walletQuery
            ->selectRaw("wallets.currency_id, SUM({$balanceSql}) as total_balance")
            ->groupBy('wallets.currency_id')
            ->get();

        $totalUsdtBalance = '0';

        foreach ($wallets as $wallet) {
            $usdtAmount = $this->convertAmountToUsdt(
                $wallet->total_balance,
                (int) $wallet->currency_id
            );

            $totalUsdtBalance = math_sum($totalUsdtBalance, $usdtAmount);
        }

        $btcRate = $this->getCurrencyToUsdtRate('BTC');

        return [
            'totalUsdBalance' => math_formatter($totalUsdtBalance, 2),
            'totalBtcBalance' => ((float) $btcRate > 0)
                ? math_formatter(math_divide($totalUsdtBalance, $btcRate), 8)
                : 0,
        ];
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
     * Edit resource.
     *
     * @param User $user
     * @return \Inertia\Response
     */
    public function edit(User $user)
    {
        \App\Support\AdminUserAccess::check($user, false);
        $model = $this->userRepository->getById($user->id, false);

        $roles = $user->roles()->pluck('name');

        return Inertia::render('Admin/Users/Form', [
            'isEdit' => true,
            'model' => $model,
            'roles' => $roles,
            'has2fa' => !empty($user->two_factor_secret),
        ]);
    }

    /**
     * Log a superadmin in as a normal user without exposing a login backdoor.
     *
     * @param Request $request
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function impersonate(Request $request, User $user)
    {
        $admin = $request->user();

        if (!$admin || !$admin->hasRole('superadmin')) {
            return response()->json([
                'success' => false,
                'message' => __('只有超级管理员可以模拟登录用户。'),
            ], 403);
        }

        if ((int) $admin->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => __('不能模拟登录自己。'),
            ], 422);
        }

        if ($user->hasRole('superadmin')) {
            return response()->json([
                'success' => false,
                'message' => __('不能模拟登录超级管理员账号。'),
            ], 422);
        }

        if ((bool) ($user->deactivated ?? false)) {
            return response()->json([
                'success' => false,
                'message' => __('该用户已停用，不能模拟登录。'),
            ], 422);
        }

        Log::warning('Superadmin impersonated user', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        $sessionData = [
            'impersonator_admin_id' => $admin->id,
            'impersonator_admin_email' => $admin->email,
            'impersonated_user_id' => $user->id,
            'impersonated_at' => now()->toDateTimeString(),
        ];

        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put($sessionData);

        return response()->json([
            'success' => true,
            'message' => __('已模拟登录该用户。'),
            'redirect' => config('fortify.home', '/markets'),
        ]);
    }

    /**
     * Update resource.
     *
     * @param UserFormRequest $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
public function update(UserFormRequest $request, User $user)
{
        \App\Support\AdminUserAccess::check($user, $request->filled('password'));
    if ($user->id == auth()->user()->getAuthIdentifier() || $user->hasRole('superadmin')) {
        if (request()->get('deactivated') || !request()->get('kyc_verified') || !request()->get('email_verified')) {
            return Redirect::back()->withErrors([
                'deactivated' => 'You can not deactivate this user or remove kyc and email status!'
            ]);
        }
    }

    $currentUser = auth()->user();

    $isSuperAdmin = false;

    if ($currentUser) {
        $currentUser->loadMissing('roles');

        $roleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        $roleIds = $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $isSuperAdmin = in_array('superadmin', $roleNames, true);
    }

    $data = [
        'deactivated' => $request->boolean('deactivated'),
        'withdrawal_disabled' => $request->boolean('withdrawal_disabled'),
        'p2p_trade_ban' => $request->boolean('p2p_trade_ban'),
        'excluded_from_transfer_fee' => $request->boolean('excluded_from_transfer_fee'),
        'is_t' => $request->boolean('is_t'),
        'nickname' => trim((string) $request->get('nickname')) ?: null,
        'leader_nickname' => trim((string) $request->get('leader_nickname')) ?: null,
    ];

    if ($isSuperAdmin) {
        $phone = trim((string) $request->get('phone'));
        $vip = (int) $request->get('vip', (int) ($user->vip ?? 0));

        $validator = Validator::make([
            'phone' => $phone,
            'vip' => $vip,
        ], [
            'phone' => [
                'nullable',
                'string',
                'max:30',
                'unique:users,phone,' . $user->id,
            ],
            'vip' => [
                'required',
                'integer',
                'min:0',
                'max:8',
            ],
        ], [
            'phone.max' => 'Phone number must not exceed 30 characters.',
            'phone.unique' => 'This phone number is already registered.',
            'vip.required' => 'VIP 等级不能为空。',
            'vip.integer' => 'VIP 等级必须是数字。',
            'vip.min' => 'VIP 等级不能小于 0。',
            'vip.max' => 'VIP 等级不能大于 8。',
        ]);

        if ($validator->fails()) {
            return Redirect::back()->withErrors($validator);
        }

        $data['phone'] = $phone ?: null;

        /*
         * 只有超级管理员可以修改 VIP。
         * 直接判断字段存在，存在才写入。
         */
        if (Schema::hasColumn('users', 'vip')) {
            $oldVip = (int) ($user->vip ?? 0);

            $data['vip'] = $vip;

            /*
             * VIP 发生变化后，标记 is_vip_update = 1。
             * 后续自动计算 VIP 的任务就不会覆盖这个用户。
             */
            if ($vip !== $oldVip && Schema::hasColumn('users', 'is_vip_update')) {
                $data['is_vip_update'] = 1;
            }
        }
    }

    if (request()->get('email_verified') && !$user->email_verified_at) {
        $data['email_verified_at'] = Carbon::now();
    } elseif (!request()->get('email_verified') && $user->email_verified_at) {
        $data['email_verified_at'] = null;
    }

    if (request()->get('kyc_verified') && !$user->kyc_verified_at) {
        $data['kyc_verified_at'] = Carbon::now();
    } elseif (!request()->get('kyc_verified') && $user->kyc_verified_at) {
        $data['kyc_verified_at'] = null;
    }

    if ($request->filled('tjremail')) {
        $refUser = User::where('email', trim((string)$request->tjremail))->first();
        if (!$refUser || (int)$refUser->id !== (int)$user->referral_id) {
            return Redirect::back()->withErrors(['tjremail'=>__('已建立的推荐关系不能在用户编辑页更换；请保留原关系并核对业务记录。')]);
        }
    }

    if ($request->filled('password')) {
        $data['password'] = Hash::make($request->password);
    }

    /*
     * 不走 userRepository，避免 Repository 或 User 模型 fillable 白名单把 vip / is_vip_update 吃掉。
     */
    DB::table('users')
        ->where('id', $user->id)
        ->update($data);

    \Illuminate\Support\Facades\Log::notice('Admin user updated', ['actor_id'=>auth()->id(),'target_id'=>$user->id,'fields'=>array_keys($data)]);
    return Redirect::route('admin.users');
}

    /**
     * Update user identity role and function permissions.
     *
     * @param Request $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateRoles(Request $request, User $user)
    {
        if (!auth()->user()->hasRole('superadmin')) {
            return Redirect::route('admin.users');
        }

        $identityRoles = $this->identityRoles();
        $permissionRoles = $this->functionPermissionRoles();

        $roleType = $request->get('role_type');

        if (!in_array($roleType, $identityRoles, true)) {
            return Redirect::back()->withErrors([
                'role_type' => __('請選擇正確的身份角色：總後台、後台、組長、業務員'),
            ]);
        }

        if ($user->id == auth()->user()->getAuthIdentifier() && $roleType !== 'superadmin') {
            return Redirect::back()->withErrors([
                'role_type' => __('不能移除自己本身的總後台身份'),
            ]);
        }

        $permissions = $request->get('permissions', []);

        if (!is_array($permissions)) {
            $permissions = [];
        }

        $syncRoles = [];

        if ($user->hasRole('user')) {
            $syncRoles[] = 'user';
        }

        $syncRoles[] = $roleType;

        foreach ($permissionRoles as $permissionRole) {
            if (!empty($permissions[$permissionRole])) {
                $syncRoles[] = $permissionRole;
            }
        }

        $syncRoles = array_values(array_unique($syncRoles));

        foreach ($syncRoles as $roleName) {
            $this->ensureRoleExists($roleName);
        }

        $user->leader_nickname = trim((string) $request->get('leader_nickname')) ?: null;
        $user->syncRoles($syncRoles);
        $user->update();

        return Redirect::route('admin.users');
    }

    protected function identityRoles(): array
    {
        return [
            'superadmin',
            'admin',
            'user_leader',
            'salesman',
        ];
    }

    protected function functionPermissionRoles(): array
    {
        return [
            ...\App\Support\UmiAdminAccess::ROLES,
            'perm_dashboard',

            'perm_markets',
            'perm_currencies',
            'perm_networks',
            'perm_bank_accounts',
            'perm_p2p',
            'perm_stakings',
            'perm_launchpads',
            'perm_quantify',
            'perm_liquidity',

            'perm_users',
            'perm_kyc_documents',
            'perm_vouchers',

            'perm_deposits',
            'perm_withdrawals',
            'perm_finances',

            'perm_pages',
            'perm_articles',
            'perm_languages',
            'perm_options_templates',
            'perm_support_tickets',
            'perm_cold_storage',

            'perm_settings',
        ];
    }

    protected function ensureRoleExists(string $roleName): void
    {
        Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => config('auth.defaults.guard', 'web'),
        ]);
    }
public function uplines(\Illuminate\Http\Request $request)
{
    $userId = (int) $request->get('user_id');

    if (!$userId) {
        return response()->json([
            'success' => false,
            'message' => 'User id is required.',
            'data' => [],
        ], 422);
    }

    $user = \App\Models\User\User::query()
        ->select([
            'id',
            'name',
            'email',
            'phone',
            'wallet_id',
            'nickname',
            'leader_nickname',
            'referral_id',
        ])
        ->where('id', $userId)
        ->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    $rows = [];
    $visited = [];
    $level = 1;
    $parentId = $user->referral_id;

    while ($parentId && $level <= 50) {
        $parentId = (int) $parentId;

        if (in_array($parentId, $visited, true)) {
            break;
        }

        $visited[] = $parentId;

        $parent = \App\Models\User\User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'wallet_id',
                'nickname',
                'leader_nickname',
                'referral_id',
            ])
            ->where('id', $parentId)
            ->first();

        if (!$parent) {
            break;
        }

        $rows[] = [
            'level' => $level,
            'id' => $parent->id,
            'account' => $parent->email ?: ($parent->phone ?: ($parent->wallet_id ?: '-')),
            'nickname' => $parent->nickname ?: '-',
            'name' => $parent->name ?: ($parent->leader_nickname ?: ($parent->nickname ?: ($parent->email ?: '-'))),
        ];

        $parentId = $parent->referral_id;
        $level++;
    }

    return response()->json([
        'success' => true,
        'data' => $rows,
    ]);
}

    public function clearVirtualRealBalances(Request $request)
    {
        if (!auth()->user()->hasRole('superadmin')) {
            return Redirect::back()->withErrors([
                'virtual_real_balances' => __('只有超级管理员可以执行该操作。'),
            ]);
        }

        if (!$this->schemaHasTableCached('users') || !$this->schemaHasColumnCached('users', 'is_xn')) {
            return Redirect::back()->withErrors([
                'virtual_real_balances' => __('users.is_xn 字段不存在，无法识别虚拟账户。'),
            ]);
        }

        if (!$this->schemaHasTableCached('wallets') || !$this->schemaHasColumnCached('wallets', 'user_id')) {
            return Redirect::back()->withErrors([
                'virtual_real_balances' => __('wallets 表或 user_id 字段不存在。'),
            ]);
        }

        $realBalanceColumns = [
            'balance_in_wallet',
            'balance_in_trade',
            'balance_in_order',
            'balance_in_withdraw',
            'balance_in_lc',
        ];
        $updates = [];

        foreach ($realBalanceColumns as $column) {
            if ($this->schemaHasColumnCached('wallets', $column)) {
                $updates[$column] = '0';
            }
        }

        if (empty($updates)) {
            return Redirect::back()->withErrors([
                'virtual_real_balances' => __('wallets 表没有可归零的真实余额字段。'),
            ]);
        }

        if ($this->schemaHasColumnCached('wallets', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        $virtualUserCount = DB::table('users')
            ->where('is_xn', true)
            ->count();

        $walletQuery = DB::table('wallets')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'wallets.user_id')
                    ->where('users.is_xn', true);
            });

        $walletCount = (clone $walletQuery)->count();
        $updatedWalletCount = $walletQuery->update($updates);

        Log::info('Admin cleared virtual user real wallet balances', [
            'admin_id' => auth()->id(),
            'virtual_user_count' => $virtualUserCount,
            'wallet_count' => $walletCount,
            'updated_wallet_count' => $updatedWalletCount,
            'columns' => array_keys($updates),
        ]);

        return Redirect::route('admin.users')->with('success', sprintf(
            __('已将 %s 个虚拟账户的 %s 个钱包真实余额归零。'),
            $virtualUserCount,
            $walletCount
        ));
    }

    /**
     * Destroy resource.
     *
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(User $user)
    {
        \App\Support\AdminUserAccess::check($user, false);
        $deleted = (new DeleteUser())->delete($user);

        if (!$deleted) {
            return Redirect::back()->withErrors(['deactivated' => 'You can not delete admin user']);
        }

        return Redirect::route('admin.users');
    }

    /**
     * Disable 2FA for a user.
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function disable2fa(User $user)
    {
        \App\Support\AdminUserAccess::check($user, true);
        if ($user->hasRole('superadmin') && !auth()->user()->hasRole('superadmin')) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot disable 2FA for a superadmin user.'
            ], 403);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->update();

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication has been disabled for this user.'
        ]);
    }
}
