<?php

namespace App\Repositories\Bonus;

use App\Models\Bonus\DepositBonus;
use App\Models\User\User;
use Illuminate\Support\Facades\Schema;

class DepositBonusRepository
{
    protected $model;

    public function __construct()
    {
        $this->model = new DepositBonus();
    }

    public function store(array $data): DepositBonus
    {
        return $this->model->create($data);
    }

    public function findExisting(string $depositId, int $userId, string $type): ?DepositBonus
    {
        return DepositBonus::where('deposit_id', $depositId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->first();
    }

    public function getReport()
    {
        $q = DepositBonus::query();

        $q->with(['user', 'sourceUser', 'currency']);
        $q->orderBy('created_at', 'desc');

        $this->applyAdminTeamScope($q);

        return $q->paginate(50)->withQueryString();
    }

    public function getReportForUser(int $userId)
    {
        $q = DepositBonus::query();

        if ($this->canAccessUser($userId)) {
            $q->where('user_id', $userId);
        } else {
            $q->whereRaw('1 = 0');
        }

        $q->with(['currency', 'sourceUser']);
        $q->orderBy('created_at', 'desc');

        return $q->paginate(50)->withQueryString();
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

    protected function applyAdminTeamScope($query)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin()) {
            return $query;
        }

        if ($this->hasTeamDataScope()) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

            if (empty($teamUserIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($where) use ($teamUserIds) {
                $where->whereIn('user_id', $teamUserIds);

                if (Schema::hasColumn('deposit_bonuses', 'source_user_id')) {
                    $where->orWhereIn('source_user_id', $teamUserIds);
                }
            });
        }

        return $query->whereRaw('1 = 0');
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