<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Wallet\AutoInvestOrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AutoInvestOrderController extends Controller
{
    protected array $superiorChainCache = [];

    protected array $userColumnCache = [];

    public function index(Request $request)
    {
        $canManageAutoInvestOrders = $this->isSuperAdmin();

        $filters = [
            'search' => trim((string) $request->get('search', '')),
            'user' => trim((string) $request->get('user', '')),
            'user_id' => trim((string) $request->get('user_id', '')),
            'superior' => trim((string) $request->get('superior', '')),
            'order' => trim((string) $request->get('order', '')),
            'currency' => trim((string) $request->get('currency', '')),
            'investment_type' => $request->get('investment_type', 'all'),
            'status' => $request->get('status', 'all'),
            'amount_min' => trim((string) $request->get('amount_min', '')),
            'amount_max' => trim((string) $request->get('amount_max', '')),
            'date_from' => trim((string) $request->get('date_from', '')),
            'date_to' => trim((string) $request->get('date_to', '')),
        ];

        if (!Schema::hasTable('auto_invest_orders')) {
            return Inertia::render('Admin/AutoInvestOrders/Index', [
                'filters' => $filters,
                'orders' => $this->emptyPaginator(),
                'can_manage_auto_invest_orders' => $canManageAutoInvestOrders,
            ]);
        }

        $select = [
            'auto_invest_orders.*',
            'users.email as user_email',
            'users.name as user_name',
            'users.referral_id as user_referral_id',
            'currencies.symbol as currency_symbol',
            'currencies.name as currency_name',
        ];

        foreach (['phone', 'wallet_id', 'nickname', 'referral_code', 'leader_nickname'] as $field) {
            if ($this->userHasColumn($field)) {
                $select[] = 'users.' . $field . ' as user_' . $field;
            }
        }

        $query = DB::table('auto_invest_orders')
            ->leftJoin('users', 'users.id', '=', 'auto_invest_orders.user_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'auto_invest_orders.currency_id')
            ->select($select);

        if (!$canManageAutoInvestOrders) {
            $teamUserIds = $this->getCurrentUserTeamIds();

            if (empty($teamUserIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('auto_invest_orders.user_id', $teamUserIds);
            }
        }

        if ($filters['status'] !== '' && $filters['status'] !== 'all') {
            $query->where('auto_invest_orders.status', $filters['status']);
        }

        if ($filters['investment_type'] !== '' && $filters['investment_type'] !== 'all') {
            $typeColumn = $this->getAutoInvestTypeColumn();

            if ($typeColumn) {
                $query->where('auto_invest_orders.' . $typeColumn, $filters['investment_type']);
            }
        }

        if ($filters['currency'] !== '') {
            $currency = $filters['currency'];

            $query->where(function ($q) use ($currency) {
                $q->where('currencies.symbol', 'like', '%' . $currency . '%')
                    ->orWhere('currencies.name', 'like', '%' . $currency . '%');

                if (is_numeric($currency)) {
                    $q->orWhere('auto_invest_orders.currency_id', (int) $currency);
                }
            });
        }

        if ($filters['user'] !== '') {
            $userSearch = $filters['user'];

            $query->where(function ($q) use ($userSearch) {
                $this->addUserSearchConditions($q, $userSearch);
            });
        }

        if ($filters['user_id'] !== '' && is_numeric($filters['user_id'])) {
            $query->where('auto_invest_orders.user_id', (int) $filters['user_id']);
        }

        if ($filters['order'] !== '') {
            $orderSearch = $filters['order'];

            $query->where(function ($q) use ($orderSearch) {
                $q->where('auto_invest_orders.order_no', 'like', '%' . $orderSearch . '%');

                if (is_numeric($orderSearch)) {
                    $q->orWhere('auto_invest_orders.id', (int) $orderSearch);
                }

                if (Schema::hasColumn('auto_invest_orders', 'order_id')) {
                    $q->orWhere('auto_invest_orders.order_id', 'like', '%' . $orderSearch . '%');
                }
            });
        }

        if ($filters['superior'] !== '') {
            $superiorDescendantIds = $this->getDescendantIdsForSuperiorKeyword($filters['superior']);

            if (empty($superiorDescendantIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('auto_invest_orders.user_id', $superiorDescendantIds);
            }
        }

        if ($filters['amount_min'] !== '' && is_numeric($filters['amount_min'])) {
            $query->where('auto_invest_orders.amount', '>=', (float) $filters['amount_min']);
        }

        if ($filters['amount_max'] !== '' && is_numeric($filters['amount_max'])) {
            $query->where('auto_invest_orders.amount', '<=', (float) $filters['amount_max']);
        }

        if ($filters['date_from'] !== '') {
            try {
                $query->where('auto_invest_orders.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
            } catch (\Throwable $e) {
                //
            }
        }

        if ($filters['date_to'] !== '') {
            try {
                $query->where('auto_invest_orders.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
            } catch (\Throwable $e) {
                //
            }
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $this->addUserSearchConditions($q, $search);

                $q->orWhere('auto_invest_orders.order_no', 'like', '%' . $search . '%')
                    ->orWhere('currencies.symbol', 'like', '%' . $search . '%')
                    ->orWhere('currencies.name', 'like', '%' . $search . '%');

                if (is_numeric($search)) {
                    $q->orWhere('auto_invest_orders.id', (int) $search)
                        ->orWhere('auto_invest_orders.user_id', (int) $search)
                        ->orWhere('auto_invest_orders.currency_id', (int) $search);
                }

                if (Schema::hasColumn('auto_invest_orders', 'order_id')) {
                    $q->orWhere('auto_invest_orders.order_id', 'like', '%' . $search . '%');
                }

                $superiorDescendantIds = $this->getDescendantIdsForSuperiorKeyword($search);

                if (!empty($superiorDescendantIds)) {
                    $q->orWhereIn('auto_invest_orders.user_id', $superiorDescendantIds);
                }
            });
        }

        $orders = $query
            ->orderByRaw("CASE WHEN auto_invest_orders.status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('auto_invest_orders.created_at')
            ->paginate(50)
            ->withQueryString();

        $orders->getCollection()->transform(function ($order) {
            return $this->formatOrder($order);
        });

        return Inertia::render('Admin/AutoInvestOrders/Index', [
            'filters' => $filters,
            'orders' => $orders,
            'can_manage_auto_invest_orders' => $canManageAutoInvestOrders,
        ]);
    }

    public function close(Request $request, $order)
    {
        $this->authorizeSuperAdmin();

        try {
            $orderRow = DB::table('auto_invest_orders')
                ->where('id', $order)
                ->first();

            if (!$orderRow) {
                return response()->json([
                    'success' => false,
                    'message' => __('量化订单不存在'),
                ], 404);
            }

            $closeCheck = $this->getOrderCloseCheck($orderRow);

            if (!$closeCheck['can_close']) {
                return response()->json([
                    'success' => false,
                    'message' => $closeCheck['reason'],
                ], 422);
            }

            $user = DB::table('users')
                ->where('id', $orderRow->user_id)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('用户不存在'),
                ], 404);
            }

            $result = (new AutoInvestOrderService())->redeemOrder($user, $orderRow->id, 'admin_redeemed', true);

            return response()->json([
                'success' => true,
                'message' => $result['closed'] ? __('量化订单已关闭，本金和收益已返还') : __('量化订单已部分赎回，剩余占用保证金继续保留'),
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function releaseMargin(Request $request, $order)
    {
        $this->authorizeSuperAdmin();

        try {
            $result = DB::transaction(function () use ($request, $order) {
                if (!Schema::hasTable('auto_invest_orders')) {
                    throw new \Exception(__('量化订单表不存在'));
                }

                if (!Schema::hasColumn('auto_invest_orders', 'used_margin')) {
                    throw new \Exception(__('量化订单缺少 used_margin 字段'));
                }

                $orderRow = DB::table('auto_invest_orders')
                    ->where('id', $order)
                    ->lockForUpdate()
                    ->first();

                if (!$orderRow) {
                    throw new \Exception(__('量化订单不存在'));
                }

                $usedMarginBefore = $this->decimal($orderRow->used_margin ?? 0);

                if ($this->compareDecimal($usedMarginBefore, 0) <= 0) {
                    throw new \Exception(__('该量化订单没有占用保证金'));
                }

                $activeLocks = $this->getActiveAutoInvestMarginLocks((int) $orderRow->id, true);
                $blockingFutures = $this->getBlockingFuturesForLocks($activeLocks);

                if (!empty($blockingFutures)) {
                    throw new \Exception(__('该量化订单仍有关联的活动合约/限价单，请先平仓或取消合约。合约ID: ') . implode(', ', $blockingFutures));
                }

                $releasedLockAmount = '0';
                $releasedLockCount = 0;

                if ($activeLocks->isNotEmpty()) {
                    foreach ($activeLocks as $lock) {
                        $lockAmount = $this->decimal($lock->amount ?? 0);

                        if ($this->compareDecimal($lockAmount, 0) <= 0) {
                            continue;
                        }

                        $lockUpdate = [
                            'status' => 'released',
                            'updated_at' => now(),
                        ];

                        if (Schema::hasColumn('auto_invest_margin_locks', 'released_amount')) {
                            $lockUpdate['released_amount'] = $lockAmount;
                        }

                        if (Schema::hasColumn('auto_invest_margin_locks', 'released_at')) {
                            $lockUpdate['released_at'] = now();
                        }

                        DB::table('auto_invest_margin_locks')
                            ->where('id', $lock->id)
                            ->update($lockUpdate);

                        $releasedLockAmount = $this->decimalAdd($releasedLockAmount, $lockAmount);
                        $releasedLockCount++;
                    }
                }

                $remainingUsedMargin = $this->getActiveUsedMarginForAutoInvestOrder((int) $orderRow->id);

                DB::table('auto_invest_orders')
                    ->where('id', $orderRow->id)
                    ->update([
                        'used_margin' => $remainingUsedMargin,
                        'updated_at' => now(),
                    ]);

                Log::warning('Admin released auto invest margin occupation', [
                    'admin_id' => optional($request->user())->id,
                    'auto_invest_order_id' => $orderRow->id,
                    'user_id' => $orderRow->user_id ?? null,
                    'used_margin_before' => $usedMarginBefore,
                    'used_margin_after' => $remainingUsedMargin,
                    'released_lock_amount' => $releasedLockAmount,
                    'released_lock_count' => $releasedLockCount,
                ]);

                return [
                    'used_margin_before' => $usedMarginBefore,
                    'used_margin_after' => $remainingUsedMargin,
                    'released_lock_amount' => $releasedLockAmount,
                    'released_lock_count' => $releasedLockCount,
                    'orphan_margin_cleared' => $activeLocks->isEmpty(),
                ];
            });

            $message = $result['orphan_margin_cleared']
                ? '已清理孤立保证金占用'
                : '已解除量化订单保证金占用';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    protected function formatOrder($order): array
    {
        $amount = (float) ($order->amount ?? 0);
        $usedMargin = (float) ($order->used_margin ?? 0);
        $principal = (float) ($order->principal_amount ?? 0);

        if ($principal <= 0) {
            $principal = $amount;
        }

        $profit = max(0, $amount - $principal);
        $availableAmount = max(0, $amount - $usedMargin);
        $investmentType = strtolower((string) ($order->investment_type ?? $order->type ?? 'fixed'));
        $maturityDate = $this->getMaturityDate($order);
        $status = (string) ($order->status ?? '');
        $closeCheck = $this->getOrderCloseCheck($order);
        $releaseMarginCheck = $this->getOrderReleaseMarginCheck($order);
        $superiors = $this->getUserSuperiorChain(
            (int) ($order->user_id ?? 0),
            $order->user_referral_id ?? null
        );

        return [
            'id' => $order->id,
            'order_no' => $order->order_no ?? null,
            'order_id' => $order->order_id ?? null,
            'user_id' => $order->user_id,
            'user_email' => $order->user_email,
            'user_name' => $order->user_name,
            'user_phone' => $order->user_phone ?? null,
            'user_wallet_id' => $order->user_wallet_id ?? null,
            'user_nickname' => $order->user_nickname ?? null,
            'user_referral_code' => $order->user_referral_code ?? null,
            'user_leader_nickname' => $order->user_leader_nickname ?? null,
            'currency_id' => $order->currency_id,
            'currency_symbol' => $order->currency_symbol,
            'currency_name' => $order->currency_name ?? null,
            'amount' => $amount,
            'principal_amount' => $principal,
            'current_profit' => $profit,
            'used_margin' => $usedMargin,
            'available_amount' => $availableAmount,
            'redeemed_amount' => (float) ($order->redeemed_amount ?? 0),
            'deducted_profit_amount' => (float) ($order->deducted_profit_amount ?? 0),
            'days' => (int) ($order->days ?? 0),
            'investment_type' => $investmentType,
            'rate' => (float) ($order->rate ?? 0),
            'vip_level' => (int) ($order->vip_level ?? 0),
            'vip_boost_rate' => (float) ($order->vip_boost_rate ?? 0),
            'status' => $status,
            'started_at' => $order->started_at ?? null,
            'matured_at' => $maturityDate ? $maturityDate->format('Y-m-d H:i:s') : null,
            'redeemed_at' => $order->redeemed_at ?? null,
            'created_at' => $order->created_at ?? null,
            'updated_at' => $order->updated_at ?? null,
            'can_close' => $closeCheck['can_close'],
            'close_block_reason' => $closeCheck['reason'],
            'can_release_margin' => $releaseMarginCheck['can_release'],
            'release_margin_block_reason' => $releaseMarginCheck['reason'],
            'superiors' => $superiors,
            'direct_superior' => $superiors[0] ?? null,
            'superior_count' => count($superiors),
        ];
    }

    protected function addUserSearchConditions($query, string $search): void
    {
        $query->where('users.email', 'like', '%' . $search . '%')
            ->orWhere('users.name', 'like', '%' . $search . '%');

        foreach (['phone', 'wallet_id', 'nickname', 'referral_code', 'leader_nickname'] as $field) {
            if ($this->userHasColumn($field)) {
                $query->orWhere('users.' . $field, 'like', '%' . $search . '%');
            }
        }

        if (is_numeric($search)) {
            $query->orWhere('users.id', (int) $search)
                ->orWhere('users.referral_id', (int) $search);
        }
    }

    protected function getDescendantIdsForSuperiorKeyword(string $keyword): array
    {
        $superiorIds = $this->findUserIdsByKeyword($keyword);

        if (empty($superiorIds)) {
            return [];
        }

        return $this->getDescendantUserIds($superiorIds);
    }

    protected function findUserIdsByKeyword(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $query = DB::table('users')
            ->select('id')
            ->where(function ($q) use ($keyword) {
                $q->where('email', 'like', '%' . $keyword . '%')
                    ->orWhere('name', 'like', '%' . $keyword . '%');

                foreach (['phone', 'wallet_id', 'nickname', 'referral_code', 'leader_nickname'] as $field) {
                    if ($this->userHasColumn($field)) {
                        $q->orWhere($field, 'like', '%' . $keyword . '%');
                    }
                }

                if (is_numeric($keyword)) {
                    $q->orWhere('id', (int) $keyword);
                }
            })
            ->limit(500);

        return $query->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter()
            ->values()
            ->toArray();
    }

    protected function getDescendantUserIds(array $parentIds): array
    {
        $allIds = [];
        $pendingIds = array_values(array_unique(array_map('intval', $parentIds)));
        $depth = 0;

        while (!empty($pendingIds) && $depth < 50) {
            $children = DB::table('users')
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->filter()
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_values(array_unique(array_merge($allIds, $children)));
            $pendingIds = $children;
            $depth++;
        }

        return $allIds;
    }

    protected function getCurrentUserTeamIds(): array
    {
        $user = auth()->user();

        if (!$user) {
            return [];
        }

        return $this->getTeamUserIds([(int) $user->id]);
    }

    protected function getTeamUserIds(array $rootIds): array
    {
        $allIds = array_values(array_unique(array_map('intval', $rootIds)));
        $pendingIds = $allIds;
        $depth = 0;

        while (!empty($pendingIds) && $depth < 50) {
            $children = DB::table('users')
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->filter()
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_values(array_unique(array_merge($allIds, $children)));
            $pendingIds = $children;
            $depth++;
        }

        return $allIds;
    }

    protected function getUserSuperiorChain(int $userId, $referralId = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (array_key_exists($userId, $this->superiorChainCache)) {
            return $this->superiorChainCache[$userId];
        }

        $parentId = $referralId;

        if (!$parentId) {
            $parentId = DB::table('users')
                ->where('id', $userId)
                ->value('referral_id');
        }

        $chain = [];
        $visited = [];
        $level = 1;

        while ($parentId && $level <= 50) {
            $parentId = (int) $parentId;

            if ($parentId <= 0 || in_array($parentId, $visited, true)) {
                break;
            }

            $visited[] = $parentId;

            $parent = DB::table('users')
                ->select($this->getSuperiorSelectColumns())
                ->where('id', $parentId)
                ->first();

            if (!$parent) {
                break;
            }

            $chain[] = $this->formatSuperiorUser($parent, $level);
            $parentId = $parent->referral_id ?? null;
            $level++;
        }

        $this->superiorChainCache[$userId] = $chain;

        return $chain;
    }

    protected function getSuperiorSelectColumns(): array
    {
        $columns = [
            'id',
            'name',
            'email',
            'referral_id',
            'created_at',
        ];

        foreach (['phone', 'wallet_id', 'nickname', 'referral_code', 'leader_nickname'] as $field) {
            if ($this->userHasColumn($field)) {
                $columns[] = $field;
            }
        }

        return $columns;
    }

    protected function formatSuperiorUser($user, int $level): array
    {
        $displayName = $this->firstNotEmpty([
            $user->leader_nickname ?? null,
            $user->nickname ?? null,
            $user->email ?? null,
            $user->phone ?? null,
            $user->wallet_id ?? null,
            $user->name ?? null,
            $user->referral_code ?? null,
        ]);

        return [
            'level' => $level,
            'id' => (int) $user->id,
            'display_name' => $displayName ?: ('UID ' . $user->id),
            'email' => $user->email ?? null,
            'name' => $user->name ?? null,
            'phone' => $user->phone ?? null,
            'wallet_id' => $user->wallet_id ?? null,
            'nickname' => $user->nickname ?? null,
            'leader_nickname' => $user->leader_nickname ?? null,
            'referral_code' => $user->referral_code ?? null,
            'created_at' => $user->created_at ?? null,
        ];
    }

    protected function firstNotEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function getAutoInvestTypeColumn(): ?string
    {
        if (Schema::hasColumn('auto_invest_orders', 'investment_type')) {
            return 'investment_type';
        }

        if (Schema::hasColumn('auto_invest_orders', 'type')) {
            return 'type';
        }

        return null;
    }

    protected function userHasColumn(string $column): bool
    {
        if (!array_key_exists($column, $this->userColumnCache)) {
            $this->userColumnCache[$column] = Schema::hasColumn('users', $column);
        }

        return $this->userColumnCache[$column];
    }

    protected function authorizeSuperAdmin(): void
    {
        if (!$this->isSuperAdmin()) {
            abort(403, 'Only super administrators can operate auto invest orders.');
        }
    }

    protected function isSuperAdmin(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        $user->loadMissing('roles');

        $roleNames = $user->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        $roleIds = $user->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        return in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);
    }

    protected function getOrderReleaseMarginCheck($order): array
    {
        $usedMargin = (float) ($order->used_margin ?? 0);

        if ($usedMargin <= 0) {
            return [
                'can_release' => false,
                'reason' => '没有占用保证金',
            ];
        }

        $activeLocks = $this->getActiveAutoInvestMarginLocks((int) ($order->id ?? 0));

        if ($activeLocks->isEmpty()) {
            return [
                'can_release' => true,
                'reason' => '可清理孤立占用',
            ];
        }

        $blockingFutures = $this->getBlockingFuturesForLocks($activeLocks);

        if (!empty($blockingFutures)) {
            return [
                'can_release' => false,
                'reason' => '仍有关联活动合约',
            ];
        }

        return [
            'can_release' => true,
            'reason' => '',
        ];
    }

    protected function getActiveAutoInvestMarginLocks(int $orderId, bool $lockForUpdate = false)
    {
        if ($orderId <= 0 || !Schema::hasTable('auto_invest_margin_locks')) {
            return collect();
        }

        $query = DB::table('auto_invest_margin_locks')
            ->where('auto_invest_order_id', $orderId);

        if (Schema::hasColumn('auto_invest_margin_locks', 'status')) {
            $query->where('status', 'active');
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    protected function getBlockingFuturesForLocks($locks): array
    {
        if (!$locks || $locks->isEmpty() || !Schema::hasTable('futures_contract')) {
            return [];
        }

        $sourceIds = $locks
            ->filter(function ($lock) {
                return ($lock->source_type ?? null) === 'futures' && !empty($lock->source_id);
            })
            ->pluck('source_id')
            ->map(function ($sourceId) {
                return (string) $sourceId;
            })
            ->unique()
            ->values()
            ->toArray();

        if (empty($sourceIds)) {
            return [];
        }

        return DB::table('futures_contract')
            ->whereIn('id', $sourceIds)
            ->whereIn('status', ['active', 'pending', 'scheduled'])
            ->pluck('id')
            ->map(function ($id) {
                return (string) $id;
            })
            ->values()
            ->toArray();
    }

    protected function getActiveUsedMarginForAutoInvestOrder(int $orderId): string
    {
        if ($orderId <= 0 || !Schema::hasTable('auto_invest_margin_locks')) {
            return '0.000000000000000000';
        }

        $query = DB::table('auto_invest_margin_locks')
            ->where('auto_invest_order_id', $orderId);

        if (Schema::hasColumn('auto_invest_margin_locks', 'status')) {
            $query->where('status', 'active');
        }

        return $this->decimal($query->sum('amount'));
    }

    protected function decimal($value, int $scale = 18): string
    {
        if ($value === null || $value === '') {
            $value = 0;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    protected function compareDecimal($left, $right): int
    {
        $left = (float) $left;
        $right = (float) $right;

        if (abs($left - $right) < 0.000000000000000001) {
            return 0;
        }

        return $left > $right ? 1 : -1;
    }

    protected function decimalAdd($left, $right): string
    {
        return $this->decimal((float) $left + (float) $right);
    }

    protected function getOrderCloseCheck($order): array
    {
        $status = (string) ($order->status ?? '');
        $amount = (float) ($order->amount ?? 0);
        $usedMargin = (float) ($order->used_margin ?? 0);

        if ($status !== 'active') {
            return [
                'can_close' => false,
                'reason' => '订单不是进行中',
            ];
        }

        if ($usedMargin > 0) {
            return [
                'can_close' => false,
                'reason' => '订单资金正在被保证金占用',
            ];
        }

        if ($amount <= 0) {
            return [
                'can_close' => false,
                'reason' => '订单可返还金额不足',
            ];
        }

        return [
            'can_close' => true,
            'reason' => '',
        ];
    }

    protected function getMaturityDate($order): ?Carbon
    {
        $dateValue = $order->maturity_date ?? $order->matured_at ?? null;

        if ($dateValue) {
            try {
                return Carbon::parse($dateValue);
            } catch (\Throwable $e) {
                //
            }
        }

        $startedAt = $order->started_at ?? $order->created_at ?? null;
        $days = (int) ($order->days ?? 0);

        if (!$startedAt || $days <= 0) {
            return null;
        }

        try {
            return Carbon::parse($startedAt)->addDays($days);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 50, 1, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }
}
