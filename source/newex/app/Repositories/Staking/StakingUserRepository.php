<?php

namespace App\Repositories\Staking;

use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use App\Models\User\User;

class StakingUserRepository
{
    /**
     * @var StakingUser
     */
    protected $staking;

    /**
     * StakingUserRepository constructor.
     */
    public function __construct()
    {
        $this->staking = new StakingUser();
    }

    public function get($isAdmin = true, $user = false, $type = 0)
    {
        $stakings = StakingUser::query();

        if ($user) {
            $stakings->where('user_id', $user->id);
        }

        if ($isAdmin) {
            $stakings->with('user');
        }

        $stakings->with([
            'staking.currency.file',
            'staking.currencyd.file',
        ]);

        $stakings->filter(request()->only(['referrer']));

        $stakings->whereHas('staking', function ($q) use ($type) {
            $q->where('staking_type', $type);
        });

        $stakings->has('currency')->has('user');

        $stakings->orderBy('id', 'desc');

        $currentUser = auth()->user();

        if (!$currentUser) {
            return $stakings->whereRaw('1 = 0')
                ->paginate(50)
                ->withQueryString();
        }

        /**
         * 前端请求：
         * 用户查看自己的 staking 数据。
         *
         * 只要 $isAdmin = false，并且传入了 $user，
         * 就直接返回当前用户自己的数据，不再走后台角色限制。
         */
        if (!$isAdmin && $user) {
            return $stakings->paginate(50)->withQueryString();
        }

        /**
         * 后台请求：
         * 下面才走管理员 / 组长权限判断。
         */
        if ($this->isSuperAdmin()) {
            return $stakings->paginate(50)->withQueryString();
        }

        $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

        if (empty($teamUserIds)) {
            return $stakings->whereRaw('1 = 0')
                ->paginate(50)
                ->withQueryString();
        }

        $stakings->whereIn('user_id', $teamUserIds);

        return $stakings->paginate(50)->withQueryString();
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

    public function getStakingById($id)
    {
        return StakingUser::find($id);
    }

    public function staking()
    {
        return $this->belongsTo(Staking::class, 'staking_id');
    }

    public function store($data)
    {
        $staking = $this->staking->create($data);

        return $staking->fresh();
    }

    public function update($id, $data)
    {
        $staking = StakingUser::find($id);
        $staking->update($data);

        return $staking->fresh();
    }

    public function delete($id)
    {
        $staking = StakingUser::find($id);
        $staking->delete();

        return true;
    }
}
