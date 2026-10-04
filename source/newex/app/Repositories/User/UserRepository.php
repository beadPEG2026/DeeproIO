<?php

namespace App\Repositories\User;

use App\Interfaces\User\UserRepositoryInterface;
use App\Models\User\User;

class UserRepository implements UserRepositoryInterface
{
    /**
     * @var User
     */
    protected $user;

    public function __construct()
    {
        $this->user = new User();
    }

    /**
     * 获取当前登录用户角色ID
     */
    protected function getCurrentRoleIds(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles->pluck('id')->map(function ($id) {
            return (int) $id;
        })->toArray();
    }

    /**
     * 获取当前登录用户角色名称
     */
    protected function getCurrentRoleNames(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles->pluck('name')->filter()->values()->toArray();
    }

    /**
     * 获取当前登录用户
     */
    protected function getCurrentUser()
    {
        return auth()->user();
    }

    /**
     * 是否超级管理员
     */
    protected function isSuperAdmin(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        if (in_array('superadmin', $roleNames, true)) {
            return true;
        }

        return (auth()->user()?->hasRole('superadmin') ?? false);
    }

    /**
     * 是否拥有团队数据权限
     */
    protected function isAdmin(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        if (count(array_intersect($roleNames, $teamScopeRoles)) > 0) {
            return true;
        }

        return (auth()->user()?->hasRole('admin') ?? false);
    }

    /**
     * 获取某个用户的完整团队ID
     * 包含自己、所有下级、下下级、无限层级
     */
    protected function getAllTeamUserIds(int $userId): array
    {
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

    /**
     * 应用数据权限
     * 规则：
     * - 超级管理员：看全部
     * - admin / user_leader / salesman / user_editor / perm_users：看自己完整团队
     * - 其他角色：不给数据
     */
    protected function applyDataScope($query)
    {
        $currentUser = $this->getCurrentUser();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin()) {
            return $query;
        }

        if ($this->isAdmin()) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

            if (empty($teamUserIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('id', $teamUserIds);
        }

        return $query->whereRaw('1 = 0');
    }

public function countToday($startDate = null, $endDate = null)
{
    $query = User::query()->authorizable();

    $this->applyDataScope($query);

    if (!empty($startDate) && !empty($endDate)) {
        $query->whereBetween('created_at', [
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    } else {
        $query->whereDate('created_at', now()->toDateString());
    }

    return $query->count();
}
public function get()
{
    $query = User::authorizable()
        ->with(['roles', 'referral'])
        ->filter(request()->only(['search']));

    $teamUserId = request()->get('team_user_id');

    // 团队筛选
    if (!empty($teamUserId)) {
        $allowed = false;

        if ($this->isSuperAdmin()) {
            $allowed = true;
        } elseif ($this->isAdmin()) {
            $myTeamUserIds = $this->getAllTeamUserIds($this->getCurrentUser()->id);
            $allowed = in_array((int) $teamUserId, array_map('intval', $myTeamUserIds), true);
        }

        if (!$allowed) {
            $query->whereRaw('1 = 0');
        } else {
            $teamUserIds = $this->getAllTeamUserIds((int) $teamUserId);

            if (empty($teamUserIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $teamUserIds);
            }
        }
    } else {
        $this->applyDataScope($query);
    }

    // 角色筛选
    if (request()->filled('role')) {
        $role = request()->get('role');
        $query->whereHas('roles', function ($q) use ($role) {
            $q->where('name', $role);
        });
    }

    // 用户状态
    if (request()->filled('status')) {
        $status = request()->get('status');

        if ($status === 'normal') {
            $query->where('deactivated', 0);
        } elseif ($status === 'deactivated') {
            $query->where('deactivated', 1);
        }
    }

    // KYC 状态
    if (request()->filled('kyc_status')) {
        $kycStatus = request()->get('kyc_status');

        if ($kycStatus === 'verified') {
            $query->whereNotNull('kyc_verified_at');
        } elseif ($kycStatus === 'unverified') {
            $query->whereNull('kyc_verified_at');
        }
    }

    // 邮箱状态
    if (request()->filled('email_status')) {
        $emailStatus = request()->get('email_status');

        if ($emailStatus === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($emailStatus === 'unverified') {
            $query->whereNull('email_verified_at');
        }
    }

    // 登录 IP
    if (request()->filled('login_ip')) {
        $query->where('login_ip', 'like', '%' . trim(request()->get('login_ip')) . '%');
    }

    // IP 归属地
    if (request()->filled('ip_location')) {
        $query->where('ip_location', 'like', '%' . trim(request()->get('ip_location')) . '%');
    }

    // Dashboard participants are filtered by activation date, not registration date.
    $participants=request()->boolean('dashboard_participants');
    $period = request()->get('period', []);
    if ($participants) {
        $query->whereIn('users.id', (new \App\Repositories\Report\ReportRepository)->dashboardParticipantIds($teamUserId ? (int)$teamUserId : null, is_array($period) ? $period : []));
    }
    if (!$participants && is_array($period) && !empty($period[0]) && !empty($period[1])) {
        $start = strlen($period[0]) <= 10 ? $period[0] . ' 00:00:00' : $period[0];
        $end = strlen($period[1]) <= 10 ? $period[1] . ' 23:59:59' : $period[1];

        $query->whereBetween('created_at', [$start, $end]);
    }

    // 重复 IP
    if (request()->filled('duplicate_ip')) {
        $query->whereIn('login_ip', function ($sub) {
            $sub->select('login_ip')
                ->from('users')
                ->whereNotNull('login_ip')
                ->where('login_ip', '!=', '')
                ->groupBy('login_ip')
                ->havingRaw('COUNT(*) > 1');
        });
    }

    // 重复账号
    // 这里我先按 email 做，如果你要改成 wallet_id，我下面也给你
    if (request()->filled('duplicate_account')) {
        $query->whereIn('email', function ($sub) {
            $sub->select('email')
                ->from('users')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->groupBy('email')
                ->havingRaw('COUNT(*) > 1');
        });
    }

    return $query->orderBy('id', 'desc')
        ->paginate(50)
        ->withQueryString();
}

    public function getById($id, $onlyActive = true)
    {
        $query = User::query();

        if ($onlyActive) {
            $query->active();
        }

        $query->authorizable();

        if ($this->isSuperAdmin()) {
            return $query->whereId($id)->first();
        }

        if ($this->isAdmin()) {
            $currentUser = $this->getCurrentUser();
            $teamUserIds = $this->getAllTeamUserIds($currentUser->id);

            return $query->whereId($id)
                ->whereIn('id', $teamUserIds)
                ->first();
        }

        return null;
    }

    public function count()
    {
        $query = User::query()->authorizable();

        $this->applyDataScope($query);

        return $query->count();
    }

    public function countOnline($teamUserId = null): int
    {
        $query = User::query()
            ->authorizable()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(10));

        $teamUserId = $teamUserId ?: request()->get('team_user_id');

        if (!empty($teamUserId)) {
            $allowed = false;

            if ($this->isSuperAdmin()) {
                $allowed = true;
            } elseif ($this->isAdmin()) {
                $currentUser = $this->getCurrentUser();
                $myTeamUserIds = $currentUser
                    ? $this->getAllTeamUserIds((int) $currentUser->id)
                    : [];

                $allowed = in_array((int) $teamUserId, array_map('intval', $myTeamUserIds), true);
            }

            if (!$allowed) {
                return 0;
            }

            $teamUserIds = $this->getAllTeamUserIds((int) $teamUserId);

            if (empty($teamUserIds)) {
                return 0;
            }

            $query->whereIn('id', $teamUserIds);
        } else {
            $this->applyDataScope($query);
        }

        return (int) $query->count();
    }

    public function update($id, $data)
    {
        $query = User::query();
        $query->authorizable();

        if ($this->isSuperAdmin()) {
            $user = $query->whereId($id)->first();
        } elseif ($this->isAdmin()) {
            $currentUser = $this->getCurrentUser();
            $teamUserIds = $this->getAllTeamUserIds($currentUser->id);

            $user = $query->whereId($id)
                ->whereIn('id', $teamUserIds)
                ->first();
        } else {
            $user = null;
        }

        if (!$user) {
            return null;
        }

        $user->update($data);

        return $user->fresh();
    }

    public function fetchByEmail($q)
    {
        $query = User::query()
            ->where('email', 'like', '%' . $q . '%');

        $this->applyDataScope($query);

        return $query->get();
    }
}
