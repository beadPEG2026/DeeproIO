<?php

namespace App\Services\Admin;

use App\Models\User\User;
use App\Repositories\Report\ReportRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGroupFilterService
{
    public const SESSION_KEY = 'admin_group_filter.team_user_id';

    protected ?array $groupOptions = null;
    protected array $teamUserIds = [];

    public function synchronizeRequest(Request $request): void
    {
        if (!$request->user()) {
            return;
        }

        if ($request->query->has('team_user_id')) {
            $requested = $request->query('team_user_id');

            if ($this->isClearValue($requested)) {
                $request->session()->forget(self::SESSION_KEY);
                $request->merge(['team_user_id' => null]);
                return;
            }

            $teamUserId = is_numeric($requested) ? (int) $requested : 0;

            if ($this->isAllowedGroup($teamUserId, $request->user())) {
                $request->session()->put(self::SESSION_KEY, $teamUserId);
                $request->merge(['team_user_id' => $teamUserId]);
                return;
            }

            $request->session()->forget(self::SESSION_KEY);
            $request->merge(['team_user_id' => null]);
            return;
        }

        $storedTeamUserId = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($this->isAllowedGroup($storedTeamUserId, $request->user())) {
            $request->merge(['team_user_id' => $storedTeamUserId]);
            return;
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    public function payload(?Request $request = null): array
    {
        $request = $request ?: request();

        return [
            'selected' => $this->selectedGroupId($request),
            'options' => $this->groupOptions($request->user()),
        ];
    }

    public function selectedGroupId(?Request $request = null): ?int
    {
        $request = $request ?: request();
        $teamUserId = (int) $request->get('team_user_id', 0);

        if (!$this->isAllowedGroup($teamUserId, $request->user())) {
            return null;
        }

        return $teamUserId;
    }

    public function selectedUserIds(?Request $request = null): ?array
    {
        $teamUserId = $this->selectedGroupId($request);

        return $teamUserId ? $this->getAllTeamUserIds($teamUserId) : null;
    }

    public function applyToQuery($query, string $userColumn = 'user_id')
    {
        $userIds = $this->selectedUserIds();

        if ($userIds === null) {
            return $query;
        }

        if (empty($userIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($userColumn, $userIds);
    }

    public function groupOptions(?User $currentUser = null): array
    {
        if ($this->groupOptions !== null) {
            return $this->groupOptions;
        }

        $currentUser = $currentUser ?: auth()->user();

        if (!$currentUser) {
            return $this->groupOptions = [];
        }

        $currentUser->loadMissing('roles');

        $roleIds = $currentUser->roles
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $roleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
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

        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

        if (!$isSuperAdmin) {
            $hasAdminScope = in_array('admin', $roleNames, true) || (auth()->user()?->hasRole('admin') ?? false);

            if ($hasAdminScope) {
                $query->whereIn('users.id', $this->getAllTeamUserIds((int) $currentUser->id));
            } elseif (
                in_array('user_leader', $roleNames, true) ||
                in_array('salesman', $roleNames, true)
            ) {
                $query->where('users.id', (int) $currentUser->id);
            } else {
                return $this->groupOptions = [];
            }
        }

        return $this->groupOptions = $query->get()
            ->map(fn ($user) => $this->formatGroupLeader($user))
            ->values()
            ->toArray();
    }

    public function getAllTeamUserIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (array_key_exists($userId, $this->teamUserIds)) {
            return $this->teamUserIds[$userId];
        }

        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = User::query()
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return $this->teamUserIds[$userId] = array_values(array_unique(array_map('intval', $allIds)));
    }

    protected function isAllowedGroup(int $teamUserId, ?User $currentUser = null): bool
    {
        if ($teamUserId <= 0) {
            return false;
        }

        return collect($this->groupOptions($currentUser))
            ->contains(fn ($group) => (int) ($group['id'] ?? 0) === $teamUserId);
    }

    protected function isClearValue($value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === '0' || $value === 'all';
    }

    protected function formatGroupLeader($user): array
    {
        $leaderNickname = trim((string) ($user->leader_nickname ?? ''));
        $email = trim((string) ($user->email ?? ''));
        $walletId = trim((string) ($user->wallet_id ?? ''));
        $name = trim((string) ($user->name ?? ''));
        $account = $email ?: ($walletId ?: ($name ?: 'User #' . $user->id));
        $displayName = $leaderNickname ?: $account;

        return [
            'id' => (int) $user->id,
            'name' => $displayName,
            'display_name' => $displayName,
            'leader_nickname' => $leaderNickname,
            'account' => $account,
            'email' => $email,
            'wallet_id' => $walletId,
            'direct_count' => (int) ($user->direct_count ?? 0),
        ];
    }
}
