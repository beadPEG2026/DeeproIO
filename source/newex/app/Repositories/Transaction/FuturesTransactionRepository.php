<?php

namespace App\Repositories\Transaction;

use App\Models\Order\FuturesContract;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;

class FuturesTransactionRepository
{
    public function count()
    {
        $transaction = FuturesContract::query();

        return $transaction->count();
    }

public function getTotalFuturesFeeAmount($teamUserId = null)
{
    $query = FuturesContract::query();

    $scopeUserIds = $this->resolveScopeUserIds($teamUserId ? (int) $teamUserId : null);

    if (is_array($scopeUserIds)) {
        if (empty($scopeUserIds)) {
            return 0;
        }

        $query->whereIn('user_id', $scopeUserIds);
    }

    /*
     * 只统计真实账户：
     * users.is_xn = false 的用户才参与合约手续费统计。
     * is_xn = true 的虚拟账户用户全部排除。
     */
    $query->whereHas('user', function ($userQuery) {
        $userQuery->where('is_xn', false);
    });

    $total = $query
        ->selectRaw('SUM(COALESCE(entry_fee,0) + COALESCE(exit_fee,0)) as total')
        ->value('total');

    return math_formatter($total ?: 0, 2);
}

    public function getActivePositionCount()
    {
        $transaction = FuturesContract::query();

        $transaction->has('market')
            ->has('user')
            ->with(['market.quoteCurrency', 'market.baseCurrency', 'user']);

        $this->excludeVirtualUsers($transaction);

        $this->applyReportFilters($transaction);

        $referrer = request()->get('referrer');
        $scopeUserIds = $this->resolveScopeUserIds($referrer ? (int) $referrer : null);

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return 0;
            }

            $transaction->whereIn('user_id', $scopeUserIds);
        }

        $transaction->where('status', 'active');

        return $transaction->count();
    }

    public function getReport()
    {
        $transaction = FuturesContract::query();

        $transaction->has('market')
            ->has('user')
            ->with(['market.quoteCurrency', 'market.baseCurrency', 'user']);

        $this->excludeVirtualUsers($transaction);

        $this->applyReportFilters($transaction);

        $referrer = request()->get('referrer');
        $scopeUserIds = $this->resolveScopeUserIds($referrer ? (int) $referrer : null);

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return $transaction->whereRaw('1 = 0')
                    ->paginate($this->getPerPage())
                    ->withQueryString();
            }

            $transaction->whereIn('user_id', $scopeUserIds);
        }

        $transaction->orderByRaw("status = 'active' DESC")
            ->orderByLatest();

        return $transaction->paginate($this->getPerPage())->withQueryString();
    }

    protected function applyReportFilters($transaction): void
    {
        if (request()->filled('search')) {
            $search = trim(request()->get('search'));

            $transaction->where(function ($query) use ($search) {
                $query->where('id', 'like', '%' . $search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('referral_code', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('market', function ($marketQuery) use ($search) {
                        $marketQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if (request()->filled('market')) {
            $market = request()->get('market');

            $transaction->whereHas('market', function ($query) use ($market) {
                $query->where('name', $market);
            });
        }

        if (request()->filled('status')) {
            $status = request()->get('status');

            if ($status === 'history') {
                $transaction->whereIn('status', ['closed', 'liquidated']);
            } else {
                $transaction->where('status', $status);
            }
        }

        if (request()->filled('type')) {
            $transaction->where('type', request()->get('type'));
        }

        $period = request()->get('period', []);

        if (is_array($period) && !empty($period[0]) && !empty($period[1])) {
            $start = strlen($period[0]) <= 10 ? $period[0] . ' 00:00:01' : $period[0];
            $end = strlen($period[1]) <= 10 ? $period[1] . ' 23:59:59' : $period[1];

            $transaction->whereBetween('created_at', [$start, $end]);
        }
    }

    protected function excludeVirtualUsers($transaction): void
    {
        $transaction->whereHas('user', function ($userQuery) {
            $userQuery->where(function ($query) {
                $query->where('is_xn', false)
                    ->orWhereNull('is_xn');
            });
        });
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

    protected function getPerPage(): int
    {
        $perPage = (int) request()->get('per_page', 50);

        if (!in_array($perPage, [10, 50, 100, 500], true)) {
            return 50;
        }

        return $perPage;
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

        $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);
        $teamUserIds = array_map('intval', $teamUserIds);

        return in_array((int) $userId, $teamUserIds, true);
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

    public function getReportUser(User $user, $limit = 15, $start = false, $end = false, $paginate = false)
    {
        $transaction = FuturesContract::query();

        $transaction->filterUser(request()->only(['market', 'type']))->orderByLatest();

        $transaction->has('market')->has('user');

        $transaction->with(['market.quoteCurrency', 'market.baseCurrency']);

        if ($start && $end) {
            $transaction->whereBetween('created_at', [$start, $end]);
        }

        if ($this->canAccessUser((int) $user->id)) {
            $transaction->where('user_id', $user->id);
        } else {
            $transaction->whereRaw('1 = 0');
        }

        if ($paginate) {
            return $transaction->simplePaginate($limit);
        }

        return $transaction->paginate(50)->withQueryString();
    }

    public function getStatReport($period)
    {
        return DB::table('futures_contract')
            ->selectRaw('markets.name as pair, currencies.symbol, SUM(futures_contract.balance - futures_contract.released_amount) as income, COUNT(*) as total')
            ->join('currencies', 'currencies.id', 'futures_contract.quote_currency_id')
            ->join('markets', 'markets.id', 'futures_contract.market_id')
            ->where('futures_contract.status', '!=', 'active')
            ->whereBetween('futures_contract.created_at', $period)
            ->groupByRaw('markets.name, currencies.symbol')
            ->get();
    }
}
