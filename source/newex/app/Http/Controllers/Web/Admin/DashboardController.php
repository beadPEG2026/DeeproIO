<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use App\Repositories\Report\ReportRepository;
use App\Services\Performance\ReadModelCacheService;
use App\Services\SystemMonitor\SystemMonitorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Inertia\Inertia;
use Setting;

class DashboardController extends Controller
{
    public function index()
    {
        if (\App\Support\UmiAdminAccess::allows(auth()->user(), 'read') && !auth()->user()->hasAnyRole(\App\Support\AdminAccess::EXCHANGE_ROLES)) {
            return redirect()->route('admin.umi.operations');
        }
        $startDate = Request::get('start_date');
        $endDate = Request::get('end_date');

        $teamUserId = Request::get('team_user_id');
        $teamUserId = $teamUserId !== null && $teamUserId !== '' ? (int) $teamUserId : null;

        if ($teamUserId && !in_array($teamUserId, ReportRepository::DASHBOARD_TEAM_USER_IDS, true)) {
            $teamUserId = null;
        }

        $currentUser = auth()->user();

        /*
         * 组长列表不按 is_xn 过滤。
         *
         * 规则：
         * 上级 / 组长即使是虚拟账户，也可以作为筛选入口。
         * 真正统计时，只过滤最终下级用户是否 is_xn = true。
         */
        $groupLeaders = $this->getGroupLeaders($currentUser);

        $allowedGroupIds = collect($groupLeaders)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        /*
         * 只校验这个组长是否在当前管理员可见范围内。
         * 不校验这个组长是不是虚拟账户。
         */
        if ($teamUserId && !in_array($teamUserId, $allowedGroupIds, true)) {
            $teamUserId = null;
        }

        Request::merge([
            'team_user_id' => $teamUserId,
            'exclude_virtual_accounts' => 1,
            'exclude_is_xn' => 1,
        ]);

        /*
         * ReportRepository 里面负责：
         * 1. 查完整团队树，不管中间上级是不是虚拟账户
         * 2. 最终只统计 is_xn = false / NULL 的下级用户
         */
        $statsCacheKey = 'admin_dashboard:stats:v1:' . hash('sha256', json_encode([
            'admin_id' => (int) ($currentUser->id ?? 0),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'team_user_id' => $teamUserId,
        ]));

        $stats = app(ReadModelCacheService::class)->rememberDashboard(
            $statsCacheKey,
            function () use ($startDate, $endDate, $teamUserId) {
                return (new ReportRepository())->getDashboardStats($startDate, $endDate, $teamUserId);
            }
        );

        $activeGroup = $this->getActiveGroup($groupLeaders, $teamUserId);

        if (is_array($stats)) {
            $groupDisplayName = $activeGroup ? $activeGroup['display_name'] : '全部';

            $stats['groupName'] = $groupDisplayName;
            $stats['group_name'] = $groupDisplayName;
            $stats['team_user_id'] = $teamUserId;
        }

        $service = new SystemMonitorService();

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'services' => $service->getStatus(),
            'operationsHealth' => app(\App\Services\SystemMonitor\OperationsHealth::class)->summary(),
            'last' => Setting::get('system-monitor.last_checked', ''),
            'version' => app(\App\Services\SystemMonitor\ReleaseInfo::class)->version(),
            'readonlyMode' => config('app.readonly', false),
            'reportPeriod' => (new ReportRepository())->dashboardReportPeriod($startDate, $endDate),
            'filters' => Request::only(['start_date', 'end_date', 'team_user_id']),
            'groupLeaders' => $groupLeaders,
            'activeGroup' => $activeGroup,
        ]);
    }

    /**
     * Login page
     *
     * @return \Illuminate\Http\Response
     */
    public function login()
    {
        $user = auth()->user();

        if (\App\Support\AdminAccess::allows($user)) {
            return Inertia::location(route('admin.dashboard'));
        }

        return Inertia::render('Auth/LoginAdmin', [
            'currentAccount' => $user ? ['email' => $user->email] : null,
        ]);
    }

    /**
     * 获取所有分组组长。
     *
     * 显示规则：
     * 1. 有 leader_nickname，显示 leader_nickname
     * 2. 没有 leader_nickname，显示账户
     * 3. 账户优先 email，没有 email 用 wallet_id，再没有用 name
     *
     * 重要：
     * 这里不要过滤 users.is_xn。
     * 因为上级 / 组长可以是虚拟账户，只作为筛选入口。
     * 最终统计时只过滤下级用户是否 is_xn = true。
     */
    protected function getGroupLeaders($currentUser): array
    {
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

        $query = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.wallet_id',
                'users.leader_nickname',
            ])
            ->selectSub(function ($query) {
                $query->from('users as direct_users')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('direct_users.referral_id', 'users.id');
            }, 'direct_count')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users as child_users')
                    ->whereColumn('child_users.referral_id', 'users.id');
            })
            ->whereIn('users.id', ReportRepository::DASHBOARD_TEAM_USER_IDS)
            ->orderByRaw("
                COALESCE(
                    NULLIF(users.leader_nickname, ''),
                    NULLIF(users.email, ''),
                    NULLIF(users.wallet_id, ''),
                    NULLIF(users.name, '')
                ) ASC
            ");

        /*
         * 超级管理员：
         * 显示所有有下级的用户作为组长入口。
         * 不过滤 is_xn。
         */
        if ((auth()->user()?->hasRole('superadmin') ?? false)) {
            return $query->get()
                ->map(function ($user) {
                    return $this->formatGroupLeader($user);
                })
                ->values()
                ->toArray();
        }

        /*
         * 普通管理员：
         * 只显示自己完整团队里的组长。
         * 完整团队树不按 is_xn 过滤，避免中间虚拟账户把下面真实用户断掉。
         */
        if ((auth()->user()?->hasRole('admin') ?? false)) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

            $allowedIds = array_values(array_unique(array_merge(
                [(int) $currentUser->id],
                array_map('intval', $teamUserIds)
            )));

            if (empty($allowedIds)) {
                return [];
            }

            return $query->whereIn('users.id', $allowedIds)
                ->get()
                ->map(function ($user) {
                    return $this->formatGroupLeader($user);
                })
                ->values()
                ->toArray();
        }

        /*
         * 组长 / 业务员也会进入仪表盘。
         * 这里不影响他们默认看到自己权限范围内的数据，只限制“组长筛选”入口。
         */
        $teamRoleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        if (in_array('user_leader', $teamRoleNames, true) || in_array('salesman', $teamRoleNames, true)) {
            if (!in_array((int) $currentUser->id, ReportRepository::DASHBOARD_TEAM_USER_IDS, true)) {
                return [];
            }

            return User::query()
                ->select([
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.wallet_id',
                    'users.leader_nickname',
                ])
                ->selectSub(function ($query) {
                    $query->from('users as direct_users')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('direct_users.referral_id', 'users.id');
                }, 'direct_count')
                ->where('users.id', $currentUser->id)
                ->get()
                ->map(function ($user) {
                    return $this->formatGroupLeader($user);
                })
                ->values()
                ->toArray();
        }

        return [];
    }

    protected function formatGroupLeader($user): array
    {
        $leaderNickname = trim((string) ($user->leader_nickname ?? ''));
        $email = trim((string) ($user->email ?? ''));
        $walletId = trim((string) ($user->wallet_id ?? ''));
        $name = trim((string) ($user->name ?? ''));

        $account = '';

        if ($email !== '') {
            $account = $email;
        } elseif ($walletId !== '') {
            $account = $walletId;
        } elseif ($name !== '') {
            $account = $name;
        } else {
            $account = '用户 #' . $user->id;
        }

        $displayName = $leaderNickname !== '' ? $leaderNickname : $account;

        return [
            'id' => (int) $user->id,
            'name' => $displayName,
            'display_name' => $displayName,
            'leader_nickname' => $leaderNickname,
            'account' => $account,
            'email' => $email,
            'wallet_id' => $walletId,
            'raw_name' => $name,
            'direct_count' => (int) ($user->direct_count ?? 0),
        ];
    }

    protected function getActiveGroup(array $groupLeaders, $teamUserId)
    {
        if (!$teamUserId) {
            return null;
        }

        foreach ($groupLeaders as $groupLeader) {
            if ((int) $groupLeader['id'] === (int) $teamUserId) {
                return $groupLeader;
            }
        }

        return null;
    }

    protected function getAllTeamUserIds(int $userId): array
    {
        /*
         * 完整团队树：
         * 不过滤 is_xn。
         *
         * 这样可以保证：
         * 上级是虚拟账户，但下级不是虚拟账户时，
         * 下级仍然可以被 ReportRepository 找到并参与统计。
         */
        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = User::query()
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return array_values(array_unique(array_map('intval', $allIds)));
    }
}
