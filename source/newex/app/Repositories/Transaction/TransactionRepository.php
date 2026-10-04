<?php

namespace App\Repositories\Transaction;

use App\Models\Transaction\Transaction;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;

class TransactionRepository
{
    public function count()
    {
        $transaction = Transaction::query();

        $transaction->maker();

        return $transaction->count();
    }

    public function getReport()
    {
        $transaction = Transaction::query();

        $transaction->orderByLatest();

        $transaction->has('market')->has('user');

        $transaction->with([
            'market.quoteCurrency',
            'market.baseCurrency',
            'user',
        ]);

        $this->applyReportFilters($transaction);

        $referrer = request()->get('referrer');
        $scopeUserIds = $this->resolveScopeUserIds($referrer ? (int) $referrer : null);

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return $transaction->whereRaw('1 = 0')->paginate($this->getPerPage())->withQueryString();
            }

            $transaction->whereIn('transactions.user_id', $scopeUserIds);
        }

        return $transaction->paginate($this->getPerPage())->withQueryString();
    }

    protected function applyReportFilters($transaction): void
    {
        $search = trim((string) request()->get('search', ''));

        if ($search !== '') {
            $transaction->where(function ($query) use ($search) {
                if (is_numeric($search)) {
                    $query->orWhere('transactions.id', (int) $search)
                        ->orWhere('transactions.user_id', (int) $search);
                }

                $query->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('email', 'like', '%' . $search . '%')
                        ->orWhere('referral_code', 'like', '%' . $search . '%')
                        ->orWhere('wallet_id', 'like', '%' . $search . '%');
                });

                $query->orWhereHas('market', function ($marketQuery) use ($search) {
                    $marketQuery->where('name', 'like', '%' . $search . '%');
                });
            });
        }

        if (request()->filled('market_id')) {
            $transaction->where('transactions.market_id', (int) request()->get('market_id'));
        }

        if (request()->filled('side')) {
            $transaction->where('transactions.order_side', request()->get('side'));
        }

        if (request()->filled('order_type')) {
            $transaction->where('transactions.order_type', request()->get('order_type'));
        }

        if (request()->filled('user_id')) {
            $transaction->where('transactions.user_id', (int) request()->get('user_id'));
        }

        if (request()->filled('email')) {
            $email = trim((string) request()->get('email'));

            $transaction->whereHas('user', function ($query) use ($email) {
                $query->where('email', 'like', '%' . $email . '%');
            });
        }

        $this->applyNumericFilter($transaction, 'transactions.price', 'price_min', '>=');
        $this->applyNumericFilter($transaction, 'transactions.price', 'price_max', '<=');

        $this->applyNumericFilter($transaction, 'transactions.fee', 'fee_min', '>=');
        $this->applyNumericFilter($transaction, 'transactions.fee', 'fee_max', '<=');

        $this->applyNumericFilter($transaction, 'transactions.base_currency', 'base_amount_min', '>=');
        $this->applyNumericFilter($transaction, 'transactions.base_currency', 'base_amount_max', '<=');

        $this->applyNumericFilter($transaction, 'transactions.quote_currency', 'quote_amount_min', '>=');
        $this->applyNumericFilter($transaction, 'transactions.quote_currency', 'quote_amount_max', '<=');

        $period = request()->get('period', []);
        $start = null;
        $end = null;

        if (is_array($period)) {
            if (!empty($period[0])) {
                $start = $period[0];

                if (strlen($start) <= 10) {
                    $start .= ' 00:00:01';
                }
            }

            if (!empty($period[1])) {
                $end = $period[1];

                if (strlen($end) <= 10) {
                    $end .= ' 23:59:59';
                }
            }
        }

        if ($start && $end) {
            $transaction->whereBetween('transactions.created_at', [$start, $end]);
        }
    }

    protected function applyNumericFilter($query, string $column, string $requestKey, string $operator): void
    {
        if (!request()->filled($requestKey)) {
            return;
        }

        $value = str_replace(',', '', trim((string) request()->get($requestKey)));

        if (!is_numeric($value)) {
            return;
        }

        $query->where($column, $operator, $value);
    }

    protected function getPerPage(): int
    {
        $perPage = (int) request()->get('per_page', 50);

        if (!in_array($perPage, [10, 50, 100, 500], true)) {
            return 50;
        }

        return $perPage;
    }

public function getTotalFeeAmount($teamUserId = null)
{
    $query = Transaction::query();

    $scopeUserIds = $this->resolveScopeUserIds($teamUserId ? (int) $teamUserId : null);

    if (is_array($scopeUserIds)) {
        if (empty($scopeUserIds)) {
            return 0;
        }

        $query->whereIn('user_id', $scopeUserIds);
    }

    /*
     * 只统计真实账户：
     * users.is_xn = true 的虚拟账户不参与现货手续费统计。
     * users.is_xn = false 或 NULL 的用户都参与统计。
     */
    $query->whereHas('user', function ($userQuery) {
        $userQuery->where(function ($q) {
            $q->where('is_xn', false)
                ->orWhereNull('is_xn');
        });
    });

    return math_formatter($query->sum('fee'), 2);
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

    public function getStatReport($period)
    {
        return DB::table('transactions')
            ->selectRaw('currencies.symbol, c2.symbol as baseSymbol, markets.name, markets.quote_precision as decimals, markets.base_precision as basedecimals, (SUM(transactions.base_currency) / 2) as baseVolume, (SUM(transactions.quote_currency) / 2) as quoteVolume, SUM(transactions.fee - transactions.referral_fee) as income, SUM(transactions.referral_fee) as referrals, COUNT(*) as total')
            ->join('markets', 'markets.id', 'transactions.market_id')
            ->join('currencies', 'currencies.id', 'markets.quote_currency_id')
            ->join('currencies as c2', 'c2.id', 'markets.base_currency_id')
            ->whereBetween('transactions.created_at', $period)
            ->groupByRaw('transactions.market_id, markets.name, markets.quote_precision, markets.base_precision, currencies.symbol, baseSymbol')
            ->get();
    }

    public function getReportUser(User $user, $limit = 15, $start = false, $end = false, $paginate = false)
    {
        $transaction = Transaction::query();

        $transaction->filterUser(request()->only(['market', 'side']))->orderByLatest();

        $transaction->has('market')->has('user');

        $transaction->with(['market.quoteCurrency', 'market.baseCurrency']);

        if ($this->canAccessUser((int) $user->id)) {
            $transaction->where('user_id', $user->id);
        } else {
            $transaction->whereRaw('1 = 0');
        }

        if ($start && $end) {
            $transaction->whereBetween('created_at', [$start, $end]);
        }

        if ($paginate) {
            return $transaction->simplePaginate($limit);
        }

        return $transaction->paginate(20)->withQueryString();
    }
}