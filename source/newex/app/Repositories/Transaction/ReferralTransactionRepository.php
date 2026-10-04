<?php

namespace App\Repositories\Transaction;

use App\Models\Transaction\ReferralTransaction;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;

class ReferralTransactionRepository
{
    /**
     * @var ReferralTransaction
     */
    protected $transaction;

    /**
     * ReferralTransactionRepository constructor.
     */
    public function __construct()
    {
        $this->transaction = new ReferralTransaction();
    }

    /**
     * @param $data
     * @return ReferralTransaction
     */
    public function store($data)
    {
        $transaction = $this->transaction->create($data);

        return $transaction;
    }

    public function count()
    {
        $transaction = ReferralTransaction::query();

        return $transaction->count();
    }

    /**
     * @return Collection
     */
    public function getPending()
    {
        $transaction = ReferralTransaction::query();

        $transaction->pending();

        return $transaction->get();
    }

    public function getReport()
    {
        $transaction = ReferralTransaction::query();

        $transaction->filter(request()->only(['search', 'referrer']))
            ->orderByLatest();

        $transaction->has('currency')->has('user');

        $transaction->with(['currency', 'user']);

        $this->applyAdminTeamScope($transaction, 'user_id');

        return $transaction->paginate(50)->withQueryString();
    }

public function getTotalReferralAmount($teamUserId = null)
{
    $query = ReferralTransaction::query()->where('is_credited',true)->where('balance_domain','real')->where('currency_id',\App\Models\Currency\Currency::where('symbol','USDT')->value('id'));

    $scopeUserIds = $this->resolveScopeUserIds($teamUserId ? (int) $teamUserId : null);

    if (is_array($scopeUserIds)) {
        if (empty($scopeUserIds)) {
            return 0;
        }

        $query->whereIn('user_id', $scopeUserIds);
    }

    /*
     * 只统计真实账户：
     * users.is_xn = false 的用户才参与返佣统计。
     * is_xn = true 的虚拟账户用户全部排除。
     */
    $query->whereHas('user', function ($userQuery) {
        $userQuery->where('is_xn', false);
    });

    return math_formatter($query->sum('amount'), 2);
}

    public function getReportUser(User $user)
    {
        $transaction = ReferralTransaction::query();

        $transaction->filter(request()->only(['search']))->orderByLatest();

        $transaction->has('currency')->has('user');

        $transaction->with(['currency']);

        if ($this->canAccessUser((int) $user->id)) {
            $transaction->where('user_id', $user->id);
        } else {
            $transaction->whereRaw('1 = 0');
        }

        return $transaction->paginate(50)->withQueryString();
    }

    protected function getCurrentRoleIds(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    protected function getCurrentRoleNames(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();
    }

    protected function isSuperAdmin(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        if (in_array('superadmin', $roleNames, true)) {
            return true;
        }

        return (auth()->user()?->hasRole('superadmin') ?? false);
    }

    protected function hasTeamDataScope(): bool
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

    protected function applyAdminTeamScope($query, string $userColumn)
    {
        $scopeUserIds = $this->resolveScopeUserIds();

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($userColumn, $scopeUserIds);
        }

        return $query;
    }

    /**
     * 返回值说明：
     * null = 超级管理员看全部，不加 whereIn
     * [] = 没有权限
     * array = 允许查看的团队用户ID
     */
    protected function resolveScopeUserIds(?int $teamUserId = null)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        if ($this->isSuperAdmin()) {
            if ($teamUserId) {
                return $this->getAllTeamUserIds($teamUserId);
            }

            return null;
        }

        if ($this->hasTeamDataScope()) {
            $myTeamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);
            $myTeamUserIds = array_map('intval', $myTeamUserIds);

            if ($teamUserId) {
                if (!in_array((int) $teamUserId, $myTeamUserIds, true)) {
                    return [];
                }

                return $this->getAllTeamUserIds((int) $teamUserId);
            }

            return $myTeamUserIds;
        }

        return [];
    }

    protected function canAccessUser(int $userId): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return false;
        }

        if ((int) $currentUser->id === (int) $userId) {
            return true;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->hasTeamDataScope()) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);
            $teamUserIds = array_map('intval', $teamUserIds);

            return in_array((int) $userId, $teamUserIds, true);
        }

        return false;
    }

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
}