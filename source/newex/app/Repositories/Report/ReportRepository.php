<?php

namespace App\Repositories\Report;

use App\Models\Market\Market;
use App\Models\Wallet\Wallet;
use App\Models\User\User;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\KycDocument\KycDocumentRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Transaction\ReferralTransactionRepository;
use App\Repositories\Transaction\TransactionRepository;
use App\Repositories\Withdrawal\FiatWithdrawalRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportRepository
{
    public const DASHBOARD_TEAM_USER_IDS = [71, 63, 61, 62];

    protected array $dashboardTableExistsCache = [];
    protected array $dashboardColumnsCache = [];
    protected ?array $dashboardCurrenciesById = null;
    protected ?array $dashboardCurrenciesBySymbol = null;
    protected ?array $dashboardUsdtRatesByCurrencyId = null;
    protected array $dashboardStakingCurrencyIdCache = [];

    public function getStats()
    {
        $depositRepository = new DepositRepository();
        $withdrawalRepository = new WithdrawalRepository();
        $transactionRepository = new TransactionRepository();
        $referralTransactionRepository = new ReferralTransactionRepository();

        $data['deposits'] = $depositRepository->count();
        $data['withdrawals'] = $withdrawalRepository->count();
        $data['trades'] = $transactionRepository->count();
        $data['referralTransactions'] = $referralTransactionRepository->count();

        return $data;
    }

    public function getDashboardStats($startDate = null, $endDate = null, $teamUserId = null)
    {
        $marketRepository = new MarketRepository();
        $currencyRepository = new CurrencyRepository();
        $kycDocumentRepository = new KycDocumentRepository();

        $teamUserId = $teamUserId ?: request('team_user_id');
        $teamUserId = $teamUserId ? (int) $teamUserId : null;

        if ($teamUserId && !in_array($teamUserId, self::DASHBOARD_TEAM_USER_IDS, true)) {
            $teamUserId = null;
        }

        /*
         * 选择了时间时，按选择时间统计。
         * 未选择时间时，除“团队总人数 / 客户余额 / 首充数据”外，
         * 其他卡片默认统计今天 00:00:00 - 23:59:59。
         *
         * 当前口径：
         * 今天数据：注册人数、做单人数、入金笔数、入金人数、出金笔数、出金金额、法币入金、法币出金、首充人数、首充金额、入金金额。
         * 全部数据：团队总人数、客户余额、存取差。
         */
        $period = $this->normalizeDashboardPeriod($startDate, $endDate);
        $metricPeriod = $this->dashboardReportPeriod($startDate, $endDate);

        $scopeUserIds = $this->getDashboardScopeUserIds($teamUserId);

        $groupName = $this->getDashboardGroupName($teamUserId);

        /*
         * 团队总人数单独计算：
         * 这里统计完整团队树，包括当前用户本人，也包括每一级下级。
         * 只影响“团队总人数”卡片，不影响入金、出金、余额等真实账户资金统计。
         */
        $teamTotalUserCount = $this->getDashboardTeamUserCount($teamUserId, $scopeUserIds);

        $data = [];

        /*
         * 平台基础数据，不按组长过滤。
         */
        $data['markets'] = $marketRepository->count();
        $data['currencies'] = $currencyRepository->count();
        $data['documents'] = $kycDocumentRepository->count();

        $data['groupName'] = $groupName;
        $data['group_name'] = $groupName;
        $data['team_user_id'] = $teamUserId;

        /*
         * 注册人数：
         * 直接按用户上下级关系往下查，不看 is_xn，不看 KYC，不看 scopeUserIds。
         * 选择组长时：统计该组长下面所有层级在时间范围内注册的人数。
         * 未选择组长时：统计全站在时间范围内注册的人数。
         */
        $dashboardRegisterCount = $this->getDashboardDownlineRegisterCount($teamUserId, $metricPeriod);
        $data['users_d'] = $dashboardRegisterCount;

        /*
         * 没有权限，或者该组长下面没有真实账户用户时，所有团队数据返回 0。
         *
         * scopeUserIds 已经是最终需要统计的真实用户。
         * 查团队树时不管中间上级是不是虚拟账户。
         * 最终统计时只排除 is_xn = true 的用户。
         */
        if (empty($scopeUserIds)) {
            $data['users'] = $teamTotalUserCount;
            $data['users_d'] = $dashboardRegisterCount;

            $data['ctc'] = math_formatter(0, 2);
            $data['cznum'] = 0;
            $data['scnum'] = 0;
            $data['firstDepositAmount'] = math_formatter(0, 2);
            $data['holdUserCount'] = 0;

            $data['depositCount'] = 0;
            $data['deposit_count'] = 0;
            $data['cryptoDepositCount'] = 0;
            $data['cryptoDeposits'] = math_formatter(0, 2);

            $data['withdrawalCount'] = 0;
            $data['withdrawal_count'] = 0;
            $data['cryptoWithdrawalCount'] = 0;
            $data['cryptoWithdrawals'] = math_formatter(0, 2);

            $data['fiatDeposits'] = 0;
            $data['fiatWithdrawals'] = 0;

            $data['totalUsdBalance'] = math_formatter(0, 2);
            $data['totalBtcBalance'] = 0;

            return $data;
        }

        /*
         * 团队总人数，只统计真实账户。
         */
        $data['users'] = max($teamTotalUserCount - 1, 0);

        /*
         * 注册人数已经在前面单独统计。
         */
        $data['users_d'] = $dashboardRegisterCount;

        /*
         * 做单人数，只统计真实账户。
         */
        $data['holdUserCount'] = $this->getDashboardHoldUserCount($scopeUserIds, $metricPeriod);

        /*
         * 入金数据：
         * 1. 查询全部币种。
         * 2. 金额按 markets.last 自动折算成 USDT。
         */
        $depositCount = $this->getDashboardDepositCount($scopeUserIds, $metricPeriod);
        $depositUserCount = $this->getDashboardDepositUserCount($scopeUserIds, $metricPeriod);

        /*
         * 入金金额卡片使用今天数据。
         * 非 USDT / USDC / USD 币种优先使用 deposits.usdt_rate 锁定汇率。
         */
        $depositAmount = $this->getDashboardDepositAmount($scopeUserIds, $metricPeriod);

        /*
         * 存取差需要使用全部数据，所以单独计算全部入金金额。
         */
        $allDepositAmount = $this->getDashboardDepositAmount($scopeUserIds, null);

        $data['depositCount'] = $depositCount;
        $data['deposit_count'] = $depositCount;
        $data['cryptoDepositCount'] = $depositCount;

        $data['cznum'] = $depositUserCount;
        $data['cryptoDeposits'] = math_formatter($depositAmount, 2);

        /*
         * 首充数据：
         * 1. 查询全部币种第一笔 confirmed 充值。
         * 2. 非 USDT / USDC / USD 优先使用 deposits.usdt_rate 折算成 USDT。
         * 3. 首充人数和首充金额按今天时间统计。
         */
        $firstDepositStats = $this->getDashboardFirstDepositStats($scopeUserIds, $metricPeriod);

        $data['scnum'] = $firstDepositStats['count'];
        $data['firstDepositAmount'] = math_formatter($firstDepositStats['amount'], 2);

        /*
         * 出金数据：
         * 1. 查询全部币种。
         * 2. 金额按 markets.last 自动折算成 USDT。
         */
        $withdrawalCount = $this->getDashboardWithdrawalCount($scopeUserIds, $metricPeriod);

        /*
         * 出金金额仍然按页面时间口径统计。
         * 但存取差卡片需要使用全部数据，所以另外计算全部出金金额。
         */
        $withdrawalAmount = $this->getDashboardWithdrawalAmount($scopeUserIds, $metricPeriod);
        $allWithdrawalAmount = $this->getDashboardWithdrawalAmount($scopeUserIds, null);

        $data['withdrawalCount'] = $withdrawalCount;
        $data['withdrawal_count'] = $withdrawalCount;
        $data['cryptoWithdrawalCount'] = $withdrawalCount;

        $data['cryptoWithdrawals'] = math_formatter($withdrawalAmount, 2);

        /*
         * 存取差：
         * 入金 USDT 折算金额 - 出金 USDT 折算金额。
         */
        /*
         * 存取差卡片使用全部数据：
         * 全部入金金额 - 全部出金金额。
         */
        $data['ctc'] = math_formatter((float) $allDepositAmount - (float) $allWithdrawalAmount, 2);

        /*
         * 法币统计：
         * 如果 fiat_deposits / fiat_withdrawals 表存在 user_id，则排除 users.is_xn = true。
         */
        $data['fiatDeposits'] = $this->getDashboardFiatCount('fiat_deposits', $scopeUserIds, $metricPeriod);
        $data['fiatWithdrawals'] = $this->getDashboardFiatCount('fiat_withdrawals', $scopeUserIds, $metricPeriod);

        /*
         * 客户余额：
         * 只统计真实账户余额，并把真实理财 / 质押 active 订单也计入。
         * 不计算 balance_in_virtual_wallet / balance_in_virtual_trade。
         * 金额按 markets.last 当前价格自动折算成 USDT。
         */
        $totalBalances = $this->getAllUserBalances('total', $scopeUserIds);

        $data['totalUsdBalance'] = $totalBalances['totalUsdBalance'];
        $data['totalBtcBalance'] = $totalBalances['totalBtcBalance'];

        return $data;
    }

    public function dashboardReportPeriod($startDate = null, $endDate = null): array
    {
        return $this->normalizeDashboardPeriod($startDate, $endDate) ?: $this->getTodayDashboardPeriod();
    }

    protected function normalizeDashboardPeriod($startDate = null, $endDate = null): ?array
    {
        if (empty($startDate) || empty($endDate)) {
            return null;
        }

        $startDate = str_replace('T', ' ', trim((string) $startDate));
        $endDate = str_replace('T', ' ', trim((string) $endDate));

        if (strlen($startDate) === 10) {
            $startDate .= ' 00:00:00';
        }

        if (strlen($endDate) === 10) {
            $endDate .= ' 23:59:59';
        }

        if (strlen($startDate) === 16) {
            $startDate .= ':00';
        }

        if (strlen($endDate) === 16) {
            $endDate .= ':59';
        }

        return [$startDate, $endDate];
    }

    protected function getTodayDashboardPeriod(): array
    {
        $today = now();

        return [
            $today->copy()->startOfDay()->format('Y-m-d H:i:s'),
            $today->copy()->endOfDay()->format('Y-m-d H:i:s'),
        ];
    }

    protected function getDashboardScopeUserIds(?int $teamUserId = null): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        $roleIds = $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $roleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        $hasTeamDataScope = count(array_intersect($roleNames, $teamScopeRoles)) > 0 || (auth()->user()?->hasRole('admin') ?? false);

        /*
         * 超级管理员：
         * 选择组长时，完整查这个组长下面所有层级，不管中间上级是不是虚拟账户。
         * 最后只返回 is_xn = false / NULL 的真实账户用户。
         */
        if ($isSuperAdmin) {
            if ($teamUserId) {
                return $this->getAllTeamUserIds((int) $teamUserId);
            }

            return User::query()
                ->where(function ($q) {
                    $q->where('is_xn', false)
                        ->orWhereNull('is_xn');
                })
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();
        }

        /*
         * 组长 / 普通管理员 / 业务员：
         * 权限判断用完整团队树，不过滤 is_xn。
         * 最终统计范围只返回真实账户。
         */
        if ($hasTeamDataScope) {
            $myTeamTreeUserIds = $this->getAllTeamTreeUserIds((int) $currentUser->id);
            $myTeamTreeUserIds = array_map('intval', $myTeamTreeUserIds);

            if (empty($myTeamTreeUserIds)) {
                return [];
            }

            if ($teamUserId) {
                /*
                 * 这里不要用过滤后的真实账户判断权限。
                 * 否则组长或中间节点是虚拟账户时，会无法筛选。
                 */
                if (!in_array((int) $teamUserId, $myTeamTreeUserIds, true)) {
                    return [];
                }

                return $this->getAllTeamUserIds((int) $teamUserId);
            }

            return $this->filterRealUserIds($myTeamTreeUserIds);
        }

        return [];
    }

    protected function getAllTeamUserIds(int $userId): array
    {
        /*
         * 先完整查团队树，不管中间任何上级是不是虚拟账户。
         * 然后只过滤最终参与统计的用户。
         */
        return $this->filterRealUserIds(
            $this->getAllTeamTreeUserIds($userId)
        );
    }

    protected function getAllTeamTreeUserIds(int $userId): array
    {
        /*
         * 完整团队树：
         * 1. 包含当前用户本人。
         * 2. 不过滤 is_xn，避免虚拟账户作为中间上级时断层。
         * 3. 逐层向下查询所有层级，不只统计直属下级。
         * 4. 使用 ID map 防止重复用户和异常循环。
         */
        $userId = (int) $userId;

        if ($userId <= 0) {
            return [];
        }

        $allIdMap = [
            $userId => true,
        ];

        $pendingIds = [$userId];
        $depthGuard = 0;

        while (!empty($pendingIds) && $depthGuard < 1000) {
            $depthGuard++;

            $nextPendingIds = [];

            foreach (array_chunk($pendingIds, 1000) as $idChunk) {
                $idChunk = array_values(array_unique(array_filter(array_map('intval', $idChunk))));

                if (empty($idChunk)) {
                    continue;
                }

                $children = User::query()
                    ->whereIn('referral_id', $idChunk)
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->toArray();

                foreach ($children as $childId) {
                    if ($childId <= 0 || isset($allIdMap[$childId])) {
                        continue;
                    }

                    $allIdMap[$childId] = true;
                    $nextPendingIds[] = $childId;
                }
            }

            $pendingIds = array_values(array_unique($nextPendingIds));
        }

        return array_values(array_map('intval', array_keys($allIdMap)));
    }

    protected function filterRealUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (empty($userIds)) {
            return [];
        }

        /*
         * 只过滤最终要统计的用户：
         * is_xn = true 不统计
         * is_xn = false / NULL 统计
         */
        return User::query()
            ->whereIn('id', $userIds)
            ->where(function ($q) {
                $q->where('is_xn', false)
                    ->orWhereNull('is_xn');
            })
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    protected function getDashboardGroupName(?int $teamUserId = null): string
    {
        if (!$teamUserId) {
            return '全部';
        }

        /*
         * 组长名称只是显示用，不过滤 is_xn。
         */
        $user = User::query()
            ->select(['id', 'name', 'email'])
            ->where('id', $teamUserId)
            ->first();

        if (!$user) {
            return '全部';
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($user->email ?? ''));

        if ($email !== '') {
            return $email;
        }

        return '用户 #' . $user->id;
    }

    protected function getDashboardTeamUserCount(?int $teamUserId = null, array $scopeUserIds = []): int
    {
        /*
         * 团队总人数口径：
         * 1. 包含当前选择的用户本人。
         * 2. 统计所有层级下级，不只统计直属下级。
         * 3. 不因为中间用户是虚拟账户而断层。
         * 4. 这里是“人数”，所以不再排除 is_xn；资金、入金、出金等金额统计仍然使用 scopeUserIds 排除虚拟账户。
         */
        $currentUser = auth()->user();

        if (!$currentUser) {
            return 0;
        }

        $currentUser->loadMissing('roles');

        $roleIds = $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $roleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        $hasTeamDataScope = count(array_intersect($roleNames, $teamScopeRoles)) > 0 || (auth()->user()?->hasRole('admin') ?? false);

        if (!$isSuperAdmin && !$hasTeamDataScope) {
            return 0;
        }

        /*
         * 选择了组长或某个团队节点：
         * 直接从该节点向下递归，统计本人 + 所有层级。
         */
        if ($teamUserId) {
            if (!$isSuperAdmin) {
                $myTreeIds = $this->getAllTeamTreeUserIds((int) $currentUser->id);

                if (!in_array((int) $teamUserId, array_map('intval', $myTreeIds), true)) {
                    return 0;
                }
            }

            return count($this->getAllTeamTreeUserIds((int) $teamUserId));
        }

        /*
         * 没有选择 team_user_id：
         * 超管看全站全部用户；组长/业务员看自己的完整团队树。
         */
        if ($isSuperAdmin) {
            return (int) User::query()->count();
        }

        return count($this->getAllTeamTreeUserIds((int) $currentUser->id));
    }

    protected function getDashboardRegisterCount(array $scopeUserIds, ?array $period = null): int
    {
        if (empty($scopeUserIds)) {
            return 0;
        }

        $query = User::query()
            ->whereIn('id', $scopeUserIds)
            ->where(function ($q) {
                $q->where('is_xn', false)
                    ->orWhereNull('is_xn');
            });

        if ($period) {
            $query->whereBetween('created_at', $period);
        } else {
            $query->whereDate('created_at', now()->toDateString());
        }

        return (int) $query->count();
    }

    protected function getDashboardDownlineRegisterCount(?int $teamUserId = null, ?array $period = null): int
    {
        /*
         * 注册人数最终口径：
         * 只按 users 表上下级关系往下查。
         * 有一个下级用户就算 1。
         * 不判断 is_xn。
         * 不判断 KYC。
         * 不依赖 scopeUserIds。
         * 不使用 User 模型，避免模型全局 Scope 或软删除等逻辑影响统计。
         *
         * 选择了组长 / 账户：统计该账户下面所有层级下级在时间范围内注册的人数。
         * 未选择组长：
         * - 超级管理员统计全站时间范围内注册人数。
         * - 普通管理员 / 组长 / 业务员统计自己下面所有层级下级在时间范围内注册人数。
         */
        if (!$this->dashboardTableExists('users')) {
            return 0;
        }

        $currentUser = auth()->user();
        $rootUserId = $teamUserId ? (int) $teamUserId : 0;

        if (!$rootUserId && $currentUser) {
            $currentUser->loadMissing('roles');

            $roleIds = $currentUser->roles
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $roleNames = $currentUser->roles
                ->pluck('name')
                ->filter()
                ->values()
                ->toArray();

            $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

            /*
             * 超管没有选择组长时，直接查全站当天/筛选时间注册数。
             */
            if ($isSuperAdmin) {
                $query = DB::table('users');
                $this->applyRegisterCreatedAtPeriod($query, $period);

                return (int) $query->count();
            }

            /*
             * 非超管没有选择组长时，从当前登录用户开始往下查。
             */
            $rootUserId = (int) $currentUser->id;
        }

        if ($rootUserId <= 0) {
            return 0;
        }

        $downlineUserIds = $this->getAllDownlineUserIdsForRegisterCount($rootUserId);

        if (empty($downlineUserIds)) {
            return 0;
        }

        $query = DB::table('users')
            ->whereIn('id', $downlineUserIds);

        $this->applyRegisterCreatedAtPeriod($query, $period);

        return (int) $query->count();
    }

    protected function getAllDownlineUserIdsForRegisterCount(int $rootUserId): array
    {
        /*
         * 注册人数专用下级递归：
         * 只按 users.referral_id 往下查。
         * 不判断权限。
         * 不判断 is_xn。
         * 不判断 KYC。
         * 不包含 rootUserId 本人，只统计他的下级。
         */
        $rootUserId = (int) $rootUserId;

        if ($rootUserId <= 0 || !$this->dashboardTableExists('users')) {
            return [];
        }

        $parentColumn = $this->getRegisterParentColumn();

        if ($parentColumn === '') {
            return [];
        }

        $allIdMap = [];
        $pendingIds = [$rootUserId];
        $depthGuard = 0;

        while (!empty($pendingIds) && $depthGuard < 1000) {
            $depthGuard++;

            $pendingIds = array_values(array_unique(array_filter(array_map('intval', $pendingIds))));

            if (empty($pendingIds)) {
                break;
            }

            $nextPendingIds = [];

            foreach (array_chunk($pendingIds, 1000) as $idChunk) {
                $children = DB::table('users')
                    ->whereIn($parentColumn, $idChunk)
                    ->pluck('id')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->toArray();

                foreach ($children as $childId) {
                    if ($childId <= 0 || $childId === $rootUserId || isset($allIdMap[$childId])) {
                        continue;
                    }

                    $allIdMap[$childId] = true;
                    $nextPendingIds[] = $childId;
                }
            }

            $pendingIds = array_values(array_unique($nextPendingIds));
        }

        return array_values(array_map('intval', array_keys($allIdMap)));
    }

    protected function getRegisterParentColumn(): string
    {
        /*
         * 你的项目正常使用 referral_id。
         * 如果 referral_id 不存在，再尝试其他常见上级字段。
         */
        $columns = [
            'referral_id',
            'referrer_id',
            'parent_id',
            'pid',
            'leader_id',
        ];

        foreach ($columns as $column) {
            if ($this->dashboardColumnExists('users', $column)) {
                return $column;
            }
        }

        return '';
    }

    protected function applyRegisterCreatedAtPeriod($query, ?array $period = null): void
    {
        if (!$this->dashboardColumnExists('users', 'created_at')) {
            return;
        }

        if ($period && count($period) >= 2) {
            $startDate = (string) $period[0];
            $endDate = (string) $period[1];

            $query->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate]);

                $startDay = substr($startDate, 0, 10);
                $endDay = substr($endDate, 0, 10);

                if ($startDay !== '' && $endDay !== '') {
                    if ($startDay === $endDay) {
                        $q->orWhereDate('created_at', $startDay);
                    } else {
                        $q->orWhereBetween(DB::raw('DATE(created_at)'), [$startDay, $endDay]);
                    }
                }
            });

            return;
        }

        $query->whereDate('created_at', now()->toDateString());
    }

    protected function applyDashboardDepositSourceScope($query): void
    {
        $query->where(function ($q) {
            $q->whereNull('source_id')
                ->orWhereNotIn('source_id', [
                    DepositRepository::ADMIN_INTERNAL_TRANSFER_SOURCE,
                    DepositRepository::PLATFORM_INTERNAL_TRANSFER_SOURCE,
                ]);
        });
    }

    protected function getDashboardDepositCount(array $scopeUserIds, ?array $period = null): int
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('deposits')) {
            return 0;
        }

        /*
         * 入金笔数：
         * 查询全部币种，不限制 currency_id。
         */
        $query = DB::table('deposits')
            ->whereIn('user_id', $scopeUserIds)
            ->where('status', $this->getDepositConfirmedStatus());

        $this->applyDashboardDepositSourceScope($query);

        if ($period) {
            $this->applyDashboardConfirmedPeriod($query, 'deposits', $period);
        }

        return (int) $query->count();
    }

    protected function getDashboardDepositUserCount(array $scopeUserIds, ?array $period = null): int
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('deposits')) {
            return 0;
        }

        /*
         * 入金人数：
         * 查询全部币种，不限制 currency_id。
         */
        $query = DB::table('deposits')
            ->whereIn('user_id', $scopeUserIds)
            ->where('status', $this->getDepositConfirmedStatus());

        $this->applyDashboardDepositSourceScope($query);

        if ($period) {
            $this->applyDashboardConfirmedPeriod($query, 'deposits', $period);
        }

        return (int) $query->distinct('user_id')->count('user_id');
    }

    protected function getDashboardDepositAmount(array $scopeUserIds, ?array $period = null): float
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('deposits')) {
            return 0;
        }

        /*
         * 入金金额：
         * 1. 查询全部币种，不限制 currency_id。
         * 2. USDT / USDC / USD 按 1:1。
         * 3. 非稳定币优先使用 deposits.usdt_rate。
         * 4. 没有 usdt_rate 时才使用 markets.last 实时价格兜底。
         */
        $hasUsdtRate = $this->dashboardColumnExists('deposits', 'usdt_rate');

        $query = DB::table('deposits')
            ->whereIn('user_id', $scopeUserIds)
            ->where('status', $this->getDepositConfirmedStatus());

        $this->applyDashboardDepositSourceScope($query);

        if ($period) {
            $this->applyDashboardConfirmedPeriod($query, 'deposits', $period);
        }

        if ($hasUsdtRate) {
            $deposits = $query
                ->selectRaw('currency_id, usdt_rate, SUM(amount) AS amount')
                ->groupBy('currency_id', 'usdt_rate')
                ->get();
        } else {
            $deposits = $query
                ->selectRaw('currency_id, SUM(amount) AS amount')
                ->groupBy('currency_id')
                ->get();
        }

        $totalUsdtAmount = '0';

        foreach ($deposits as $deposit) {
            $usdtAmount = $this->convertDepositAmountToUsdtBySavedRate(
                $deposit->amount,
                (int) $deposit->currency_id,
                $hasUsdtRate ? ($deposit->usdt_rate ?? null) : null
            );

            $totalUsdtAmount = math_sum($totalUsdtAmount, $usdtAmount);
        }

        return (float) math_formatter($totalUsdtAmount, 2, '.', '');
    }

    protected function getDashboardFirstDepositStats(array $scopeUserIds, ?array $period = null): array
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('deposits')) {
            return [
                'count' => 0,
                'amount' => 0,
            ];
        }

        /*
         * 首充统计：
         * 1. 不限制 currency_id。
         * 2. 先找到每个用户第一笔 confirmed 充值。
         * 3. 如果传入筛选时间，则第一笔充值时间在筛选时间内才参与统计。
         * 4. 首充金额优先使用 deposits.usdt_rate 锁定汇率折算 USDT。
         */
        $firstDepositSub = DB::table('deposits')
            ->selectRaw('MIN(id) as first_deposit_id')
            ->whereIn('user_id', $scopeUserIds)
            ->where('status', $this->getDepositConfirmedStatus())
            ->groupBy('user_id');

        $this->applyDashboardDepositSourceScope($firstDepositSub);

        $hasUsdtRate = $this->dashboardColumnExists('deposits', 'usdt_rate');

        $query = DB::table('deposits')
            ->whereIn('id', function ($q) use ($firstDepositSub) {
                $q->fromSub($firstDepositSub, 'first_deposits')
                    ->select('first_deposit_id');
            });

        $this->applyDashboardDepositSourceScope($query);

        if ($period) {
            $this->applyDashboardConfirmedPeriod($query, 'deposits', $period);
        }

        if ($hasUsdtRate) {
            $firstDeposits = $query
                ->selectRaw('currency_id, usdt_rate, SUM(amount) AS amount, COUNT(*) AS user_count')
                ->groupBy('currency_id', 'usdt_rate')
                ->get();
        } else {
            $firstDeposits = $query
                ->selectRaw('currency_id, SUM(amount) AS amount, COUNT(*) AS user_count')
                ->groupBy('currency_id')
                ->get();
        }

        $totalUsdtAmount = '0';
        $firstDepositUserCount = 0;

        foreach ($firstDeposits as $deposit) {
            $firstDepositUserCount += (int) ($deposit->user_count ?? 0);

            $usdtAmount = $this->convertDepositAmountToUsdtBySavedRate(
                $deposit->amount,
                (int) $deposit->currency_id,
                $hasUsdtRate ? ($deposit->usdt_rate ?? null) : null
            );

            $totalUsdtAmount = math_sum($totalUsdtAmount, $usdtAmount);
        }

        return [
            'count' => $firstDepositUserCount,
            'amount' => (float) math_formatter($totalUsdtAmount, 8, '.', ''),
        ];
    }

    protected function getDashboardWithdrawalCount(array $scopeUserIds, ?array $period = null): int
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('withdrawals')) {
            return 0;
        }

        /*
         * 出金笔数：
         * 查询全部币种，不限制 currency_id。
         */
        $query = DB::table('withdrawals')
            ->whereIn('user_id', $scopeUserIds)
            ->where('status', $this->getWithdrawalConfirmedStatus());

        if ($period) {
            $this->applyDashboardUpdatedAtPeriod($query, 'withdrawals', $period);
        }

        return (int) $query->count();
    }

    protected function getDashboardWithdrawalAmount(array $scopeUserIds, ?array $period = null): float
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('withdrawals')) {
            return 0;
        }

        /*
         * 出金金额：
         * 查询全部币种，按 markets.last 折算成 USDT。
         */
        $query = DB::table('withdrawals')
            ->whereIn('user_id', $scopeUserIds)
            ->where('status', $this->getWithdrawalConfirmedStatus());

        if ($period) {
            $this->applyDashboardUpdatedAtPeriod($query, 'withdrawals', $period);
        }

        $withdrawals = $query
            ->selectRaw('currency_id, SUM(amount) AS amount')
            ->groupBy('currency_id')
            ->get();

        $totalUsdtAmount = '0';

        foreach ($withdrawals as $withdrawal) {
            $usdtAmount = $this->convertAmountToUsdt(
                $withdrawal->amount,
                (int) $withdrawal->currency_id
            );

            $totalUsdtAmount = math_sum($totalUsdtAmount, $usdtAmount);
        }

        return (float) math_formatter($totalUsdtAmount, 2, '.', '');
    }

    protected function getDashboardHoldUserCount(array $scopeUserIds, ?array $period = null): int
    {
        if (empty($scopeUserIds) || !$this->dashboardTableExists('futures_contract')) return 0;
        return (int) $this->dashboardParticipantQuery($scopeUserIds, $period)
            ->distinct('futures_contract.user_id')->count('futures_contract.user_id');
    }

    /** The drill-down and headline share statuses, activation date and team scope. */
    public function dashboardParticipantIds(?int $teamUserId, ?array $period = null)
    {
        $period=$this->dashboardReportPeriod($period[0]??null, $period[1]??null);
        return $this->dashboardParticipantQuery($this->getDashboardScopeUserIds($teamUserId), $period)
            ->select('futures_contract.user_id')->distinct();
    }

    private function dashboardParticipantQuery(array $scopeUserIds, ?array $period = null)
    {
        $query = DB::table('futures_contract')
            ->whereIn('futures_contract.user_id', $scopeUserIds);

        /*
         * 做单人数改为真实合约开仓人数：
         * 1. 只统计真实账户。
         * 2. pending / scheduled 还没真正开仓，不参与统计。
         * 3. active / closed / liquidated 都属于已经开过仓。
         * 4. 按 user_id 去重，一个用户多笔开仓只算 1 人。
         */
        if ($this->dashboardTableExists('users') && $this->dashboardColumnExists('users', 'is_xn')) {
            $query->join('users', 'users.id', '=', 'futures_contract.user_id')
                ->where(function ($q) {
                    $q->where('users.is_xn', false)
                        ->orWhereNull('users.is_xn');
                });
        }

        if ($this->dashboardColumnExists('futures_contract', 'status')) {
            $query->whereIn('futures_contract.status', ['active', 'closed', 'liquidated']);
        }

        if ($period && count($period) >= 2) {
            $startDate = (string) $period[0];
            $endDate = (string) $period[1];

            if ($this->dashboardColumnExists('futures_contract', 'activated_at')) {
                $query->whereRaw(
                    'COALESCE(futures_contract.activated_at, futures_contract.created_at) between ? and ?',
                    [$startDate, $endDate]
                );
            } elseif ($this->dashboardColumnExists('futures_contract', 'created_at')) {
                $query->whereBetween('futures_contract.created_at', [$startDate, $endDate]);
            }
        }

        return $query;
    }

    protected function getDashboardFiatCount(string $table, array $scopeUserIds, ?array $period = null): int
    {
        if (!$this->dashboardTableExists($table)) {
            return 0;
        }

        $query = DB::table($table);

        if ($this->dashboardColumnExists($table, 'user_id')) {
            $query->join('users', "{$table}.user_id", '=', 'users.id')
                ->whereIn("{$table}.user_id", $scopeUserIds)
                ->where(function ($q) {
                    $q->where('users.is_xn', false)
                        ->orWhereNull('users.is_xn');
                });
        }

        if ($period) {
            $this->applyDashboardConfirmedPeriod($query, $table, $period);
        }

        return (int) $query->count();
    }

    /**
     * 统计用户余额
     *
     * $type:
     * total   = balance_in_wallet + balance_in_trade + active 理财 + active 质押
     * account = balance_in_wallet
     * trade   = balance_in_trade
     */
    public function getAllUserBalances($type = 'total', ?array $scopeUserIds = null)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [
                'totalUsdBalance' => 0,
                'totalBtcBalance' => 0,
            ];
        }

        $currentUser->loadMissing('roles');

        $roleIds = $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $roleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        $hasTeamDataScope = count(array_intersect($roleNames, $teamScopeRoles)) > 0 || (auth()->user()?->hasRole('admin') ?? false);

        /*
         * 余额统计只统计真实账户用户，并且只计算真实字段：
         * balance_in_wallet
         * balance_in_trade
         *
         * 不计算：
         * balance_in_virtual_wallet
         * balance_in_virtual_trade
         * balance_in_virtual_order
         */
        $walletQuery = Wallet::query()
            ->join('users', 'wallets.user_id', '=', 'users.id')
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        $balanceScopeUserIds = null;

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return [
                    'totalUsdBalance' => 0,
                    'totalBtcBalance' => 0,
                ];
            }

            $balanceScopeUserIds = array_values(array_unique(array_map('intval', $scopeUserIds)));
            $walletQuery->whereIn('wallets.user_id', $scopeUserIds);
        } else {
            if ($hasTeamDataScope && !$isSuperAdmin) {
                $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

                if (empty($teamUserIds)) {
                    return [
                        'totalUsdBalance' => 0,
                        'totalBtcBalance' => 0,
                    ];
                }

                $balanceScopeUserIds = array_values(array_unique(array_map('intval', $teamUserIds)));
                $walletQuery->whereIn('wallets.user_id', $teamUserIds);
            } elseif (!$isSuperAdmin) {
                return [
                    'totalUsdBalance' => 0,
                    'totalBtcBalance' => 0,
                ];
            }
        }

        if ($type == 'total') {
            $wallets = $walletQuery
                ->selectRaw('wallets.currency_id, SUM(COALESCE(wallets.balance_in_wallet, 0) + COALESCE(wallets.balance_in_trade, 0)) as total_balance')
                ->groupBy('wallets.currency_id')
                ->get();
        } elseif ($type == 'account') {
            $wallets = $walletQuery
                ->selectRaw('wallets.currency_id, SUM(COALESCE(wallets.balance_in_wallet, 0)) as total_balance')
                ->groupBy('wallets.currency_id')
                ->get();
        } else {
            $wallets = $walletQuery
                ->selectRaw('wallets.currency_id, SUM(COALESCE(wallets.balance_in_trade, 0)) as total_balance')
                ->groupBy('wallets.currency_id')
                ->get();
        }

        $totalUsdtBalance = '0';

        foreach ($wallets as $wallet) {
            $usdtAmount = $this->convertAmountToUsdt(
                $wallet->total_balance,
                (int) $wallet->currency_id
            );

            $totalUsdtBalance = math_sum($totalUsdtBalance, $usdtAmount);
        }

        if ($type === 'total') {
            $totalUsdtBalance = math_sum(
                $totalUsdtBalance,
                $this->getDashboardAutoInvestBalance($balanceScopeUserIds)
            );

            $totalUsdtBalance = math_sum(
                $totalUsdtBalance,
                $this->getDashboardStakingBalance($balanceScopeUserIds)
            );
        }

        $btcRate = $this->getCurrencyToUsdtRate('BTC');

        return [
            'totalUsdBalance' => math_formatter($totalUsdtBalance, 2),
            'totalBtcBalance' => ((float) $btcRate > 0)
                ? math_formatter(math_divide($totalUsdtBalance, $btcRate), 8)
                : 0,
        ];
    }

    protected function getDashboardAutoInvestBalance(?array $scopeUserIds = null): string
    {
        if (!$this->dashboardTableExists('auto_invest_orders')) {
            return '0';
        }

        if (
            !$this->dashboardColumnExists('auto_invest_orders', 'user_id') ||
            !$this->dashboardColumnExists('auto_invest_orders', 'currency_id') ||
            !$this->dashboardColumnExists('auto_invest_orders', 'amount')
        ) {
            return '0';
        }

        $selectColumns = [
            'auto_invest_orders.currency_id',
            'auto_invest_orders.amount',
        ];

        $hasMeta = $this->dashboardColumnExists('auto_invest_orders', 'meta');

        if ($hasMeta) {
            $selectColumns[] = 'auto_invest_orders.meta';
        }

        $query = DB::table('auto_invest_orders')
            ->join('users', 'users.id', '=', 'auto_invest_orders.user_id')
            ->select($selectColumns)
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return '0';
            }

            $query->whereIn('auto_invest_orders.user_id', array_values(array_unique(array_map('intval', $scopeUserIds))));
        }

        if ($this->dashboardColumnExists('auto_invest_orders', 'status')) {
            $query->where('auto_invest_orders.status', 'active');
        }

        $total = '0';

        foreach ($query->get() as $row) {
            if ($hasMeta && $this->isDashboardVirtualSourceMeta($row->meta ?? null)) {
                continue;
            }

            $total = math_sum(
                $total,
                $this->convertAmountToUsdt($row->amount ?? 0, (int) ($row->currency_id ?? 0))
            );
        }

        return $total;
    }

    protected function getDashboardStakingBalance(?array $scopeUserIds = null): string
    {
        if (!$this->dashboardTableExists('staking_users')) {
            return '0';
        }

        if (
            !$this->dashboardColumnExists('staking_users', 'user_id') ||
            !$this->dashboardColumnExists('staking_users', 'amount')
        ) {
            return '0';
        }

        $selectColumns = [
            'staking_users.amount',
        ];

        $hasCurrencyId = $this->dashboardColumnExists('staking_users', 'currency_id');
        $hasStakingId = $this->dashboardColumnExists('staking_users', 'staking_id');
        $hasMeta = $this->dashboardColumnExists('staking_users', 'meta');

        if ($hasCurrencyId) {
            $selectColumns[] = 'staking_users.currency_id';
        }

        if ($hasStakingId) {
            $selectColumns[] = 'staking_users.staking_id';
        }

        if ($hasMeta) {
            $selectColumns[] = 'staking_users.meta';
        }

        $query = DB::table('staking_users')
            ->join('users', 'users.id', '=', 'staking_users.user_id')
            ->select($selectColumns)
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return '0';
            }

            $query->whereIn('staking_users.user_id', array_values(array_unique(array_map('intval', $scopeUserIds))));
        }

        if ($this->dashboardColumnExists('staking_users', 'status')) {
            $query->where('staking_users.status', 'active');
        }

        $total = '0';

        foreach ($query->get() as $row) {
            if ($hasMeta && $this->isDashboardVirtualSourceMeta($row->meta ?? null)) {
                continue;
            }

            $currencyId = $hasCurrencyId ? (int) ($row->currency_id ?? 0) : 0;

            if ($currencyId <= 0 && $hasStakingId) {
                $currencyId = $this->getDashboardStakingProductCurrencyId((int) ($row->staking_id ?? 0));
            }

            $total = math_sum(
                $total,
                $this->convertAmountToUsdt($row->amount ?? 0, $currencyId)
            );
        }

        return $total;
    }

    protected function getDashboardStakingProductCurrencyId(int $stakingId): int
    {
        if ($stakingId <= 0 || !$this->dashboardTableExists('staking')) {
            return 0;
        }

        if (array_key_exists($stakingId, $this->dashboardStakingCurrencyIdCache)) {
            return $this->dashboardStakingCurrencyIdCache[$stakingId];
        }

        if (!$this->dashboardColumnExists('staking', 'currency_id')) {
            return 0;
        }

        $this->dashboardStakingCurrencyIdCache[$stakingId] = (int) DB::table('staking')
            ->where('id', $stakingId)
            ->value('currency_id');

        return $this->dashboardStakingCurrencyIdCache[$stakingId];
    }

    protected function isDashboardVirtualSourceMeta($meta): bool
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

    protected function convertDepositAmountToUsdtBySavedRate($amount, int $currencyId, $savedUsdtRate = null): string
    {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return '0';
        }

        $currency = $this->getDashboardCurrencyById($currencyId);

        if (!$currency || empty($currency->symbol)) {
            return '0';
        }

        $symbol = strtoupper(trim((string) $currency->symbol));

        /*
         * 稳定币直接按 1:1。
         */
        if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
            return math_formatter($amount, 2, '.', '');
        }

        /*
         * 非 USDT / USDC / USD：
         * 优先使用 deposits.usdt_rate 保存的历史锁价。
         */
        if (is_numeric($savedUsdtRate) && (float) $savedUsdtRate > 0) {
            return math_formatter(
                math_multiply($amount, $savedUsdtRate),
                2,
                '.',
                ''
            );
        }

        /*
         * 老数据没有 usdt_rate 时，才使用当前 markets.last 兜底。
         */
        return $this->convertAmountToUsdt($amount, $currencyId);
    }

    protected function convertAmountToUsdt($amount, int $currencyId): string
    {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return '0';
        }

        $currency = $this->getDashboardCurrencyById($currencyId);

        if (!$currency || empty($currency->symbol)) {
            return '0';
        }

        $symbol = strtoupper(trim((string) $currency->symbol));

        /*
         * 常见美元稳定币直接按 1:1 处理。
         */
        if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
            return math_formatter($amount, 2, '.', '');
        }

        $rate = $this->getCurrencyToUsdtRate($symbol);

        if (!is_numeric($rate) || (float) $rate <= 0) {
            return '0';
        }

        return math_formatter(
            math_multiply($amount, $rate),
            2,
            '.',
            ''
        );
    }

    protected function getCurrencyToUsdtRate(string $symbol): string
    {
        static $rateCache = [];

        $symbol = strtoupper(trim($symbol));

        if ($symbol === '' || in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
            return '1';
        }

        if (isset($rateCache[$symbol])) {
            return $rateCache[$symbol];
        }

        if (!$this->dashboardTableExists('currencies') || !$this->dashboardTableExists('markets')) {
            $rateCache[$symbol] = '0';
            return '0';
        }

        if (
            !$this->dashboardColumnExists('markets', 'base_currency_id') ||
            !$this->dashboardColumnExists('markets', 'quote_currency_id') ||
            !$this->dashboardColumnExists('markets', 'last')
        ) {
            $rateCache[$symbol] = '0';
            return '0';
        }

        $currency = $this->getDashboardCurrencyBySymbol($symbol);

        if (!$currency) {
            $rateCache[$symbol] = '0';
            return '0';
        }

        $this->loadDashboardUsdtRateCache();

        $rateCache[$symbol] = $this->dashboardUsdtRatesByCurrencyId[(int) $currency->id] ?? '0';

        return $rateCache[$symbol];
    }

    protected function getDashboardCurrencyById(int $currencyId)
    {
        if ($currencyId <= 0) {
            return null;
        }

        $this->loadDashboardCurrencyCache();

        return $this->dashboardCurrenciesById[$currencyId] ?? null;
    }

    protected function getDashboardCurrencyBySymbol(string $symbol)
    {
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '') {
            return null;
        }

        $this->loadDashboardCurrencyCache();

        return $this->dashboardCurrenciesBySymbol[$symbol] ?? null;
    }

    protected function loadDashboardCurrencyCache(): void
    {
        if ($this->dashboardCurrenciesById !== null) {
            return;
        }

        $this->dashboardCurrenciesById = [];
        $this->dashboardCurrenciesBySymbol = [];

        if (!$this->dashboardTableExists('currencies')) {
            return;
        }

        $currencies = DB::table('currencies')
            ->select(['id', 'symbol', 'rate'])
            ->get();

        foreach ($currencies as $currency) {
            $currencyId = (int) ($currency->id ?? 0);
            $symbol = strtoupper(trim((string) ($currency->symbol ?? '')));

            if ($currencyId <= 0 || $symbol === '') {
                continue;
            }

            $this->dashboardCurrenciesById[$currencyId] = $currency;
            $this->dashboardCurrenciesBySymbol[$symbol] = $currency;
        }
    }

    protected function loadDashboardUsdtRateCache(): void
    {
        if ($this->dashboardUsdtRatesByCurrencyId !== null) {
            return;
        }

        $this->dashboardUsdtRatesByCurrencyId = [];
        $this->loadDashboardCurrencyCache();

        if (empty($this->dashboardCurrenciesById)) {
            return;
        }

        $usdt = $this->dashboardCurrenciesBySymbol['USDT'] ?? null;
        $usd = $this->dashboardCurrenciesBySymbol['USD'] ?? null;
        $quotePriority = [];

        if ($usdt) {
            $quotePriority[(int) $usdt->id] = 0;
        }

        if ($usd && !array_key_exists((int) $usd->id, $quotePriority)) {
            $quotePriority[(int) $usd->id] = 1;
        }

        $directRates = [];
        $reverseRates = [];

        if (
            !empty($quotePriority) &&
            $this->dashboardTableExists('markets') &&
            $this->dashboardColumnExists('markets', 'base_currency_id') &&
            $this->dashboardColumnExists('markets', 'quote_currency_id') &&
            $this->dashboardColumnExists('markets', 'last')
        ) {
            $quoteIds = array_keys($quotePriority);

            $markets = DB::table('markets')
                ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
                ->whereNotNull('last')
                ->where('last', '>', 0)
                ->where(function ($query) use ($quoteIds) {
                    $query->whereIn('quote_currency_id', $quoteIds)
                        ->orWhereIn('base_currency_id', $quoteIds);
                })
                ->orderBy('id')
                ->get();

            foreach ($markets as $market) {
                $baseCurrencyId = (int) ($market->base_currency_id ?? 0);
                $quoteCurrencyId = (int) ($market->quote_currency_id ?? 0);
                $last = $market->last ?? null;

                if ($baseCurrencyId <= 0 || $quoteCurrencyId <= 0 || !is_numeric($last) || (float) $last <= 0) {
                    continue;
                }

                if (array_key_exists($quoteCurrencyId, $quotePriority) && !array_key_exists($baseCurrencyId, $quotePriority)) {
                    $priority = $quotePriority[$quoteCurrencyId];

                    if (!isset($directRates[$baseCurrencyId]) || $priority < $directRates[$baseCurrencyId]['priority']) {
                        $directRates[$baseCurrencyId] = [
                            'priority' => $priority,
                            'rate' => math_formatter($last, 18, '.', ''),
                        ];
                    }
                }

                if (array_key_exists($baseCurrencyId, $quotePriority) && !array_key_exists($quoteCurrencyId, $quotePriority)) {
                    $priority = $quotePriority[$baseCurrencyId];

                    if (!isset($reverseRates[$quoteCurrencyId]) || $priority < $reverseRates[$quoteCurrencyId]['priority']) {
                        $reverseRates[$quoteCurrencyId] = [
                            'priority' => $priority,
                            'rate' => math_formatter(math_divide(1, $last), 18, '.', ''),
                        ];
                    }
                }
            }
        }

        foreach ($this->dashboardCurrenciesById as $currencyId => $currency) {
            $symbol = strtoupper(trim((string) ($currency->symbol ?? '')));

            if (in_array($symbol, ['USDT', 'USDC', 'USD'], true)) {
                $this->dashboardUsdtRatesByCurrencyId[$currencyId] = '1';
            } elseif (isset($directRates[$currencyId])) {
                $this->dashboardUsdtRatesByCurrencyId[$currencyId] = $directRates[$currencyId]['rate'];
            } elseif (isset($reverseRates[$currencyId])) {
                $this->dashboardUsdtRatesByCurrencyId[$currencyId] = $reverseRates[$currencyId]['rate'];
            } elseif (isset($currency->rate) && is_numeric($currency->rate) && (float) $currency->rate > 0) {
                $this->dashboardUsdtRatesByCurrencyId[$currencyId] = math_formatter($currency->rate, 18, '.', '');
            } else {
                $this->dashboardUsdtRatesByCurrencyId[$currencyId] = '0';
            }
        }
    }

    protected function getDepositConfirmedStatus()
    {
        if (defined('DEPOSIT_CONFIRMED')) {
            return constant('DEPOSIT_CONFIRMED');
        }

        return 1;
    }

    protected function getWithdrawalConfirmedStatus()
    {
        if (defined('WITHDRAWAL_CONFIRMED_BY_PROVIDER')) {
            return constant('WITHDRAWAL_CONFIRMED_BY_PROVIDER');
        }

        return 1;
    }

    protected function applyDashboardConfirmedPeriod($query, string $table, ?array $period = null): void
    {
        if (!$period || count($period) < 2 || empty($period[0]) || empty($period[1])) {
            return;
        }

        $hasCreatedAt = $this->dashboardColumnExists($table, 'created_at');
        $hasUpdatedAt = $this->dashboardColumnExists($table, 'updated_at');

        if (!$hasCreatedAt && !$hasUpdatedAt) {
            return;
        }

        $startDate = $this->normalizeDashboardReportDate($period[0]);
        $endDate = $this->normalizeDashboardReportDate($period[1]);
        $storageTimezone = $this->dashboardReportStorageTimezone();
        $displayTimezone = $this->dashboardReportDisplayTimezone();

        $query->where(function ($q) use ($table, $startDate, $endDate, $storageTimezone, $displayTimezone, $hasCreatedAt, $hasUpdatedAt) {
            if ($hasCreatedAt) {
                $q->whereRaw(
                    "(({$table}.created_at AT TIME ZONE '{$storageTimezone}') AT TIME ZONE '{$displayTimezone}')::date between ? and ?",
                    [$startDate, $endDate]
                );
            }

            if ($hasUpdatedAt) {
                $method = $hasCreatedAt ? 'orWhereRaw' : 'whereRaw';

                $q->{$method}(
                    "(({$table}.updated_at AT TIME ZONE '{$storageTimezone}') AT TIME ZONE '{$displayTimezone}')::date between ? and ?",
                    [$startDate, $endDate]
                );
            }
        });
    }

    protected function applyDashboardUpdatedAtPeriod($query, string $table, ?array $period = null): void
    {
        if (!$period || count($period) < 2 || empty($period[0]) || empty($period[1])) {
            return;
        }

        if (!$this->dashboardColumnExists($table, 'updated_at')) {
            return;
        }

        $startDate = $this->normalizeDashboardReportDate($period[0]);
        $endDate = $this->normalizeDashboardReportDate($period[1]);
        $storageTimezone = $this->dashboardReportStorageTimezone();
        $displayTimezone = $this->dashboardReportDisplayTimezone();

        $query->whereRaw(
            "(({$table}.updated_at AT TIME ZONE '{$storageTimezone}') AT TIME ZONE '{$displayTimezone}')::date between ? and ?",
            [$startDate, $endDate]
        );
    }

    protected function normalizeDashboardReportDate($value): string
    {
        $value = str_replace('T', ' ', trim((string) $value));
        $value = rtrim($value, 'Z');

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
            return $matches[0];
        }

        return substr($value, 0, 10);
    }

    protected function dashboardReportStorageTimezone(): string
    {
        return $this->validDashboardReportTimezone(config('app.timezone', 'UTC'), 'UTC');
    }

    protected function dashboardReportDisplayTimezone(): string
    {
        return 'UTC';
    }

    protected function validDashboardReportTimezone($timezone, string $fallback): string
    {
        $timezone = (string) ($timezone ?: $fallback);

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : $fallback;
    }

    protected function dashboardTableExists(string $table): bool
    {
        if (array_key_exists($table, $this->dashboardTableExistsCache)) {
            return $this->dashboardTableExistsCache[$table];
        }

        $cacheKey = $this->dashboardSchemaCacheKey('table', $table);

        try {
            $cached = $this->dashboardCacheStore()->get($cacheKey);

            if (is_bool($cached)) {
                $this->dashboardTableExistsCache[$table] = $cached;
                return $cached;
            }
        } catch (\Throwable $e) {
            // Continue with the database schema lookup when the cache is unavailable.
        }

        try {
            $exists = Schema::hasTable($table);
            $this->dashboardTableExistsCache[$table] = $exists;

            try {
                $this->dashboardCacheStore()->put(
                    $cacheKey,
                    $exists,
                    max(300, (int) config('performance.schema_ttl_seconds', 86400))
                );
            } catch (\Throwable $e) {
                // The request-level cache above still prevents duplicate lookups.
            }
        } catch (\Throwable $e) {
            $this->dashboardTableExistsCache[$table] = false;
        }

        return $this->dashboardTableExistsCache[$table];
    }

    protected function dashboardColumnExists(string $table, string $column): bool
    {
        if (array_key_exists($table, $this->dashboardColumnsCache)) {
            return isset($this->dashboardColumnsCache[$table][$column]);
        }

        if (!$this->dashboardTableExists($table)) {
            $this->dashboardColumnsCache[$table] = [];
            return false;
        }

        $cacheKey = $this->dashboardSchemaCacheKey('columns', $table);

        try {
            $cached = $this->dashboardCacheStore()->get($cacheKey);

            if (is_array($cached)) {
                $this->dashboardColumnsCache[$table] = $cached;
                return isset($cached[$column]);
            }
        } catch (\Throwable $e) {
            // Continue with the database schema lookup when the cache is unavailable.
        }

        try {
            $columns = Schema::getColumnListing($table);
            $this->dashboardColumnsCache[$table] = array_fill_keys($columns, true);

            try {
                $this->dashboardCacheStore()->put(
                    $cacheKey,
                    $this->dashboardColumnsCache[$table],
                    max(300, (int) config('performance.schema_ttl_seconds', 86400))
                );
            } catch (\Throwable $e) {
                // The request-level cache above still prevents duplicate lookups.
            }
        } catch (\Throwable $e) {
            $this->dashboardColumnsCache[$table] = [];
            return false;
        }

        return isset($this->dashboardColumnsCache[$table][$column]);
    }

    protected function dashboardSchemaCacheKey(string $type, string $table): string
    {
        $connection = (string) config('database.default', 'default');

        return "admin_dashboard:schema:v1:{$connection}:{$type}:{$table}";
    }

    protected function dashboardCacheStore()
    {
        return Cache::store((string) config('performance.cache_store', 'redis'));
    }
}
