<?php

namespace App\Repositories\Deposit;

use App\Interfaces\Deposit\DepositRepositoryInterface;
use App\Models\Deposit\Deposit;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use App\Support\AdminReportFilters;

class DepositRepository implements DepositRepositoryInterface
{
    public const ADMIN_INTERNAL_TRANSFER_SOURCE = 'admin_internal_transfer';
    public const PLATFORM_INTERNAL_TRANSFER_SOURCE = 'internal';

    /**
     * @var Deposit
     */
    protected $deposit;

    /**
     * DepositRepository constructor.
     */
    public function __construct()
    {
        $this->deposit = new Deposit();
    }

    public function get()
    {
        $deposit = Deposit::query();

        $deposit->with('currency.file');

        $deposit->has('currency');
        $deposit->where('amount', '>', 0);

        /*
         * 不显示虚拟账户用户数据。
         */
        $this->applyRealUserScopeToEloquent($deposit);
        $this->excludeAdminInternalTransfer($deposit);

        $deposit->orderBy('created_at', 'desc');

        $deposit->limit(10);

        return $deposit->get();
    }

    public function exportReport()
    {
        $deposit = Deposit::query();

        $deposit->has('currency')->has('user');
        $deposit->with(['currency', 'network', 'user']);
        $this->applyAdminDepositAmountVisibility($deposit);
        $deposit->orderBy('created_at', 'desc');

        /*
         * 导出也排除虚拟账户用户。
         */
        $this->applyRealUserScopeToEloquent($deposit);
        $this->excludeAdminInternalTransfer($deposit);

        $this->applyReportFilters($deposit);

        $this->applyAdminTeamScope($deposit, 'deposits.user_id');

        $list = $deposit->get();

        $fileName = 'deposits_' . date('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];

        $columns = [
            '创建时间',
            '确认时间',
            '状态',
            'ID',
            '交易哈希',
            '金额',
            '币种',
            '地址',
            '网络',
            '用户邮箱',
            '用户ID',
            '确认数',
        ];

        $callback = function () use ($list, $columns) {
            $file = fopen('php://output', 'w');

            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, $columns);

            foreach ($list as $item) {
                fputcsv($file, [
                    $item->created_at,
                    $item->updated_at ?: $item->created_at,
                    $item->status == 'confirmed' || $item->status == DEPOSIT_CONFIRMED ? '已确认' : '待确认',
                    $item->deposit_id,
                    $item->txn,
                    $item->amount,
                    optional($item->currency)->symbol,
                    $item->address,
                    optional($item->network)->name,
                    optional($item->user)->email,
                    optional($item->user)->id,
                    $item->confirms,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function getReport()
    {
        $deposit = Deposit::query();

        $deposit->has('currency')->has('user');
        $deposit->with(['currency', 'network', 'user']);
        $this->applyAdminDepositAmountVisibility($deposit);
        $deposit->orderBy('created_at', 'desc');

        /*
         * 充值列表不显示虚拟账户用户数据：
         * users.is_xn = true 的用户不显示。
         * users.is_xn = false 或 NULL 的用户显示。
         */
        $this->applyRealUserScopeToEloquent($deposit);
        $this->excludeAdminInternalTransfer($deposit);

        $this->applyReportFilters($deposit);

        $this->applyAdminTeamScope($deposit, 'deposits.user_id');

        $paginated = $deposit->paginate(AdminReportFilters::perPage())->withQueryString();
        $this->appendUsdtAmountToDeposits($paginated->getCollection());

        return $paginated;
    }

    protected function appendUsdtAmountToDeposits($deposits): void
    {
        foreach ($deposits as $item) {
            $amount = $item->amount ?? 0;
            $currencyId = (int) ($item->currency_id ?? 0);
            $currency = $item->relationLoaded('currency') ? $item->currency : null;

            if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0 || !$currency) {
                $item->setAttribute('usdt_amount', '0');
                continue;
            }

            if ($this->isUsdStableCurrency($currency)) {
                $item->setAttribute('usdt_amount', math_formatter($amount, 8, '.', ''));
                continue;
            }

            $savedRate = $item->usdt_rate ?? null;
            $rate = is_numeric($savedRate) && (float) $savedRate > 0
                ? $savedRate
                : $this->getCurrencyToUsdtRateByCurrencyId($currencyId);

            if (!is_numeric($rate) || (float) $rate <= 0) {
                $item->setAttribute('usdt_amount', '0');
                continue;
            }

            $item->setAttribute(
                'usdt_amount',
                math_formatter(math_multiply($amount, $rate), 8, '.', '')
            );
        }
    }

    protected function applyReportFilters($deposit): void
    {
        $filters = request()->only([
            'search',
            'type',
            'status',
            'network_id',
            'user_id',
            'txn',
            'address',
            'period',
            'referrer',
        ]);

        if (request()->boolean('first_only')) {
            $first = DB::table('deposits')->selectRaw('MIN(id)')->where('status', DEPOSIT_CONFIRMED)->groupBy('user_id');
            $this->excludeAdminInternalTransfer($first);
            $deposit->whereIn('deposits.id', $first);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $deposit->where(function ($q) use ($search) {
                $q->where('deposits.deposit_id', 'like', "%{$search}%")
                    ->orWhere('deposits.txn', 'like', "%{$search}%")
                    ->orWhere('deposits.address', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%");
                        if (ctype_digit($search)) $uq->orWhere('id', $search);
                    });
            });
        }

        if (!empty($filters['type'])) {
            $deposit->where('deposits.type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $deposit->where('deposits.status', $filters['status']);
        }

        if (!empty($filters['network_id'])) {
            $deposit->where('deposits.network_id', $filters['network_id']);
        }

        if (!empty($filters['user_id'])) {
            $deposit->where('deposits.user_id', $filters['user_id']);
        }

        if (!empty($filters['txn'])) {
            $deposit->where('deposits.txn', 'like', '%' . trim($filters['txn']) . '%');
        }

        if (!empty($filters['address'])) {
            $deposit->where('deposits.address', 'like', '%' . trim($filters['address']) . '%');
        }

        if ($period = AdminReportFilters::period()) {
            $this->applyCreatedOrUpdatedDateRange($deposit, $period[0], $period[1]);
        }

        if (!empty($filters['referrer'])) {
            $deposit->where('deposits.user_id', (int) $filters['referrer']);
        }
    }

    protected function applyAdminDepositAmountVisibility($deposit): void
    {
        $deposit->where('deposits.amount', '>', 0);

        // Reporting includes every positive deposit; crediting policy is unchanged.
    }

    protected function whereCurrencySymbolIn($query, array $symbols): void
    {
        $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));

        $query->where(function ($q) use ($symbols) {
            $q->whereIn(DB::raw('UPPER(symbol)'), $symbols)
                ->orWhereIn(DB::raw('UPPER(alt_symbol)'), $symbols);
        });
    }

    public function getStatReport($period)
    {
        $query = DB::table('deposits')
            ->join('users', 'deposits.user_id', '=', 'users.id')
            ->join('currencies', 'currencies.id', 'deposits.currency_id')
            ->selectRaw('currencies.symbol as name, SUM(deposits.amount) as volume, SUM(deposits.system_fee) as income, COUNT(*) as total')
            ->where('deposits.status', DEPOSIT_CONFIRMED)
            ->where('deposits.amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('deposits.source_id')
                    ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
            })
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            })
            ->groupByRaw('currencies.symbol');

        if (is_array($period) && count($period) === 2) {
            $this->applyCreatedOrUpdatedDateRange($query, $period[0], $period[1]);
        }

        return $query->get();
    }

    /**
     * 充值人数，去重用户
     */
    public function getDepositUserCountAll($startDate = null, $endDate = null)
    {
        $hasDateRange = !empty($startDate) && !empty($endDate);

        $query = DB::table('deposits')
            ->join('users', 'deposits.user_id', '=', 'users.id')
            ->where('deposits.status', DEPOSIT_CONFIRMED)
            ->where('deposits.amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('deposits.source_id')
                    ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
            })
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if ($hasDateRange) {
            $this->applyCreatedOrUpdatedDateRange($query,
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59'
            );
        }

        $scopeUserIds = $this->resolveScopeUserIds();

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return 0;
            }

            $query->whereIn('deposits.user_id', $scopeUserIds);
        }

        return $query
            ->distinct('deposits.user_id')
            ->count('deposits.user_id');
    }

    /**
     * 首次充值人数
     */
    public function getFirstDepositUserCountAll($startDate = null, $endDate = null)
    {
        $hasDateRange = !empty($startDate) && !empty($endDate);

        $query = DB::table('deposits')
            ->join('users', 'deposits.user_id', '=', 'users.id')
            ->where('deposits.status', DEPOSIT_CONFIRMED)
            ->where('deposits.amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('deposits.source_id')
                    ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
            })
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if ($hasDateRange) {
            $query->whereBetween('deposits.created_at', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59',
            ]);
        }

        $scopeUserIds = $this->resolveScopeUserIds();

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return 0;
            }

            $query->whereIn('deposits.user_id', $scopeUserIds);
        }

        return $query
            ->distinct('deposits.user_id')
            ->count('deposits.user_id');
    }

    public function getFirstDepositAmountAll($startDate = null, $endDate = null)
    {
        $hasDateRange = !empty($startDate) && !empty($endDate);

        $scopeUserIds = $this->resolveScopeUserIds();

        if (is_array($scopeUserIds) && empty($scopeUserIds)) {
            return math_formatter(0, 2);
        }

        $subQuery = DB::table('deposits')
            ->join('users', 'deposits.user_id', '=', 'users.id')
            ->selectRaw('deposits.user_id, MIN(deposits.created_at) as first_deposit_at')
            ->where('deposits.status', DEPOSIT_CONFIRMED)
            ->where('deposits.amount', '>', 0)
            ->where('deposits.network_id', '!=', 0)
            ->where(function ($q) {
                $q->whereNull('deposits.source_id')
                    ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
            })
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if (is_array($scopeUserIds)) {
            $subQuery->whereIn('deposits.user_id', $scopeUserIds);
        }

        if ($hasDateRange) {
            $subQuery->whereBetween('deposits.created_at', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59',
            ]);
        }

        $subQuery->groupBy('deposits.user_id');

        $amount = DB::table('deposits')
            ->join('users', 'deposits.user_id', '=', 'users.id')
            ->joinSub($subQuery, 'first_deposits', function ($join) {
                $join->on('deposits.user_id', '=', 'first_deposits.user_id')
                    ->on('deposits.created_at', '=', 'first_deposits.first_deposit_at');
            })
            ->where('deposits.status', DEPOSIT_CONFIRMED)
            ->where('deposits.amount', '>', 0)
            ->where('deposits.network_id', '!=', 0)
            ->where(function ($q) {
                $q->whereNull('deposits.source_id')
                    ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
            })
            ->where(function ($q) {
                $q->where('users.is_xn', false)
                    ->orWhereNull('users.is_xn');
            });

        if (is_array($scopeUserIds)) {
            $amount->whereIn('deposits.user_id', $scopeUserIds);
        }

        if ($hasDateRange) {
            $amount->whereBetween('deposits.created_at', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59',
            ]);
        }

        $amount = $amount->sum('deposits.amount');

        return math_formatter($amount, 2);
    }

    public function getReportUser(User $user, $pagination = true, $limit = 1000, $start = false, $end = false, $simplePagination = false)
    {
        $deposit = Deposit::query();

        $deposit->filterUser(request()->only(['currency', 'network', 'txn', 'status']))->orderByLatest();

        $deposit->has('currency');

        $deposit->with(['currency.file']);

        // Only receipt-backed ignored records are safe to expose as observed chain deposits.
        // Preserve legacy hidden records, whose reason/ownership has not been reconciled.
        $deposit->where(function ($q) {
            $q->where('status', '!=', DEPOSIT_IGNORED)->orWhere('source_id', 'like', 'trx:%')->orWhere('source_id', 'like', 'verified:%');
        });
        $deposit->where('amount', '>', 0);

        /*
         * 如果当前用户是虚拟账户，则不显示充值记录。
         */
        if ((bool) ($user->is_xn ?? false)) {
            $deposit->whereRaw('1 = 0');
        } elseif ($this->canAccessUser((int) $user->id)) {
            $deposit->where('user_id', $user->id);
        } else {
            $deposit->whereRaw('1 = 0');
        }

        if ($start && $end) {
            $deposit->whereBetween('created_at', [$start, $end]);
        }

        if ($simplePagination) {
            return $deposit->simplePaginate($limit);
        }

        if (!$pagination) {
            if ($limit == 0 || $limit > 1000) {
                $limit = 1000;
            }

            $deposit->limit($limit);

            return $deposit->get();
        }

        return $deposit->paginate(50)->withQueryString();
    }

    public function getTotalDepositAmount($teamUserId = null, $startDate = null, $endDate = null)
{
    $hasDateRange = !empty($startDate) && !empty($endDate);

    $hasDepositUsdtRate = \Illuminate\Support\Facades\Schema::hasColumn('deposits', 'usdt_rate');

    $selectColumns = [
        'deposits.amount',
        'deposits.currency_id',
    ];

    if ($hasDepositUsdtRate) {
        $selectColumns[] = 'deposits.usdt_rate';
    }

    $query = DB::table('deposits')
        ->join('users', 'deposits.user_id', '=', 'users.id')
        ->where('deposits.status', DEPOSIT_CONFIRMED)
        ->where(function ($q) {
            $q->whereNull('deposits.source_id')
                ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
        })

        /*
         * 这里不要再限制 currency_id。
         * 所有币种充值都要统计，然后折算成 USDT。
         */
        ->where('users.is_t', false)
        ->where(function ($q) {
            $q->where('users.is_xn', false)
                ->orWhereNull('users.is_xn');
        });

    if ($hasDateRange) {
        $this->applyCreatedOrUpdatedDateRange($query,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59'
        );
    }

    $scopeUserIds = $this->resolveScopeUserIds($teamUserId ? (int) $teamUserId : null);

    if (is_array($scopeUserIds)) {
        if (empty($scopeUserIds)) {
            return math_formatter(0, 2);
        }

        $query->whereIn('deposits.user_id', $scopeUserIds);
    }

    $deposits = $query
        ->select($selectColumns)
        ->get();

    $totalUsdtAmount = '0';

    foreach ($deposits as $deposit) {
        $amount = $deposit->amount ?? 0;
        $currencyId = (int) ($deposit->currency_id ?? 0);

        if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0) {
            continue;
        }

        /*
         * 如果充值记录已经保存过 usdt_rate，优先使用保存的汇率。
         * 这样历史充值金额不会因为 markets.last 变化而变化。
         */
        $savedRate = $hasDepositUsdtRate ? ($deposit->usdt_rate ?? null) : null;

        if (is_numeric($savedRate) && (float) $savedRate > 0) {
            $usdtAmount = math_multiply($amount, $savedRate);
        } else {
            /*
             * 没有保存汇率时，使用当前币种价格折算成 USDT。
             */
            $usdtAmount = $this->convertAmountToUsdt($amount, $currencyId);
        }

        $totalUsdtAmount = math_sum($totalUsdtAmount, $usdtAmount);
    }

    return math_formatter($totalUsdtAmount, 2);
}

protected function applyCreatedOrUpdatedDateRange($query, $start, $end): void
{
    if (empty($start) || empty($end)) {
        return;
    }

    $startDate = $this->normalizeReportDate($start);
    $endDate = $this->normalizeReportDate($end);
    $timezone = $this->reportDisplayTimezone();

    $query->where(function ($q) use ($startDate, $endDate, $timezone) {
        $q->whereRaw(
            "((deposits.created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}')::date between ? and ?",
            [$startDate, $endDate]
        )->orWhereRaw(
            "((deposits.updated_at AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}')::date between ? and ?",
            [$startDate, $endDate]
        );
    });
}

protected function normalizeReportDate($value): string
{
    $value = str_replace('T', ' ', trim((string) $value));
    $value = rtrim($value, 'Z');

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
        return $matches[0];
    }

    return substr($value, 0, 10);
}

protected function reportDisplayTimezone(): string
{
    return 'America/Los_Angeles';
}

protected function convertAmountToUsdt($amount, int $currencyId): string
{
    if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0) {
        return '0';
    }

    $currency = DB::table('currencies')
        ->select(['id', 'symbol', 'alt_symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        return '0';
    }

    /*
     * 稳定币按 1:1 处理。
     */
    if ($this->isUsdStableCurrency($currency)) {
        return math_formatter($amount, 8, '.', '');
    }

    $rate = $this->getCurrencyToUsdtRateByCurrencyId($currencyId);

    if (!is_numeric($rate) || (float) $rate <= 0) {
        return '0';
    }

    return math_formatter(
        math_multiply($amount, $rate),
        8,
        '.',
        ''
    );
}

protected function getCurrencyToUsdtRateByCurrencyId(int $currencyId): string
{
    static $rateCache = [];

    if ($currencyId <= 0) {
        return '0';
    }

    if (isset($rateCache[$currencyId])) {
        return $rateCache[$currencyId];
    }

    $currency = DB::table('currencies')
        ->select(['id', 'symbol', 'alt_symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    if ($this->isUsdStableCurrency($currency)) {
        $rateCache[$currencyId] = '1';
        return '1';
    }

    $usdt = DB::table('currencies')
        ->select(['id', 'symbol'])
        ->whereRaw('UPPER(symbol) = ?', ['USDT'])
        ->first();

    $usd = DB::table('currencies')
        ->select(['id', 'symbol'])
        ->whereRaw('UPPER(symbol) = ?', ['USD'])
        ->first();

    $quoteIds = [];

    if ($usdt) {
        $quoteIds[] = (int) $usdt->id;
    }

    if ($usd && !in_array((int) $usd->id, $quoteIds, true)) {
        $quoteIds[] = (int) $usd->id;
    }

    if (empty($quoteIds)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    /*
     * 正向交易对：
     * 当前币种 / USDT 或 当前币种 / USD
     * markets.last 就是该代币价格。
     */
    $directQuery = DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->where('base_currency_id', $currencyId)
        ->whereIn('quote_currency_id', $quoteIds)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $directQuery->orderByRaw(
            'CASE WHEN quote_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END'
        );
    }

    $directMarket = $directQuery->first();

    if ($directMarket && is_numeric($directMarket->last) && (float) $directMarket->last > 0) {
        $rateCache[$currencyId] = math_formatter($directMarket->last, 18, '.', '');
        return $rateCache[$currencyId];
    }

    /*
     * 反向交易对：
     * USDT / 当前币种 或 USD / 当前币种
     * 当前币种转 USDT 汇率 = 1 / markets.last。
     */
    $reverseQuery = DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->whereIn('base_currency_id', $quoteIds)
        ->where('quote_currency_id', $currencyId)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $reverseQuery->orderByRaw(
            'CASE WHEN base_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END'
        );
    }

    $reverseMarket = $reverseQuery->first();

    if ($reverseMarket && is_numeric($reverseMarket->last) && (float) $reverseMarket->last > 0) {
        $rateCache[$currencyId] = math_formatter(
            math_divide(1, $reverseMarket->last),
            18,
            '.',
            ''
        );

        return $rateCache[$currencyId];
    }

    $rateCache[$currencyId] = '0';

    return '0';
}

protected function isUsdStableCurrencySymbol(string $symbol): bool
{
    $normalizedSymbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', $symbol));

    if ($normalizedSymbol === '') {
        return false;
    }

    if (in_array($normalizedSymbol, ['USD', 'USDT', 'USDC'], true)) {
        return true;
    }

    return str_starts_with($normalizedSymbol, 'USDT')
        || str_starts_with($normalizedSymbol, 'USDC');
}

protected function isUsdStableCurrency($currency): bool
{
    return $this->isUsdStableCurrencySymbol((string) ($currency->symbol ?? ''))
        || $this->isUsdStableCurrencySymbol((string) ($currency->alt_symbol ?? ''));
}

    protected function applyRealUserScopeToEloquent($query)
    {
        return $query->whereHas('user', function ($userQuery) {
            $userQuery->where(function ($q) {
                $q->where('is_xn', false)
                    ->orWhereNull('is_xn');
            });
        });
    }

    protected function excludeAdminInternalTransfer($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('deposits.source_id')
                ->orWhereNotIn('deposits.source_id', self::excludedAdminDepositSourceIds());
        });
    }

    protected static function excludedAdminDepositSourceIds(): array
    {
        return [
            self::ADMIN_INTERNAL_TRANSFER_SOURCE,
            self::PLATFORM_INTERNAL_TRANSFER_SOURCE,
        ];
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
     * array = 允许查看的团队真实用户ID
     */
    protected function resolveScopeUserIds(?int $teamUserId = null)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        /*
         * 如果外部没有传 teamUserId，则尝试读取请求里的 team_user_id。
         */
        if (!$teamUserId && request()->filled('team_user_id')) {
            $teamUserId = (int) request()->get('team_user_id');
        }

        /*
         * 超级管理员：
         * 不选组长时看全部，由外层真实账户条件过滤。
         * 选择组长时，先查完整团队树，再只返回真实账户用户ID。
         */
        if ($this->isSuperAdmin()) {
            if ($teamUserId) {
                return $this->getAllTeamUserIds((int) $teamUserId);
            }

            return null;
        }

        /*
         * 普通管理员 / 组长 / 业务员：
         * 权限判断使用完整团队树，不过滤 is_xn。
         * 最终统计范围只返回真实账户用户ID。
         */
        if ($this->hasTeamDataScope()) {
            $myTeamTreeUserIds = $this->getAllTeamTreeUserIds((int) $currentUser->id);
            $myTeamTreeUserIds = array_map('intval', $myTeamTreeUserIds);

            if (empty($myTeamTreeUserIds)) {
                return [];
            }

            if ($teamUserId) {
                if (!in_array((int) $teamUserId, $myTeamTreeUserIds, true)) {
                    return [];
                }

                return $this->getAllTeamUserIds((int) $teamUserId);
            }

            return $this->filterRealUserIds($myTeamTreeUserIds);
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
            /*
             * 权限判断用完整团队树，不过滤 is_xn。
             * 这样不会因为中间上级是虚拟账户而断层。
             */
            $teamUserIds = $this->getAllTeamTreeUserIds((int) $currentUser->id);
            $teamUserIds = array_map('intval', $teamUserIds);

            return in_array((int) $userId, $teamUserIds, true);
        }

        return false;
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
         * 不过滤 is_xn。
         *
         * 这样可以保证：
         * 上级是虚拟账户，但下级不是虚拟账户时，
         * 下级仍然会被找到并参与统计。
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

    public function count()
    {
        $deposit = Deposit::query();

        $this->applyRealUserScopeToEloquent($deposit);
        $this->excludeAdminInternalTransfer($deposit);

        return $deposit->count();
    }

    public function getDeposit($id)
    {
        return Deposit::with('currency')->whereId($id)->first();
    }

    public function store($data)
    {
        return $this->deposit->create($data);
    }

    public function update($deposit, $data)
    {
        return $deposit->update($data);
    }

    public function getBySource($source_id, $network)
    {
        return Deposit::with('currency')->where('source_id', $source_id)->where('network_id', $network)->first();
    }

    public function getByTxn($txn, $network)
    {
        return Deposit::with('currency')->where('txn', $txn)->where('network_id', $network)->first();
    }

    public function getByNetwork($network, $status = 'pending')
    {
        return Deposit::with('currency')->whereStatus($status)->where('network_id', $network)->get();
    }

    public function getByNetworks($networks, $status = 'pending')
    {
        return Deposit::with('currency')->whereStatus($status)->where(function ($query) use ($networks) {
            $query->where('network_id', $networks[0]);
            $query->orWhere('network_id', $networks[1]);
        })->get();
    }
}
