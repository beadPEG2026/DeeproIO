<?php

namespace App\Http\Controllers\Web\Admin;

use App\Console\Commands\Bnb\MonitorBepDepositsCommand;
use App\Console\Commands\Bnb\MonitorBnbDepositsCommand;
use App\Console\Commands\Customtoken\HandleDeposit;
use App\Console\Commands\Customtoken\MonitorTokenDepositsCommand;
use App\Console\Commands\Ethereum\MonitorErcDepositsCommand;
use App\Console\Commands\Ethereum\MonitorEthereumDepositsCommand;
use App\Console\Commands\Polygon\MonitorMatic20DepositsCommand;
use App\Console\Commands\Polygon\MonitorMaticDepositsCommand;
use App\Console\Commands\Tron\MonitorTrcDepositsCommand;
use App\Console\Commands\Tron\MonitorTrxDepositsCommand;
use App\Events\WalletUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Currency\CurrencyLiteCollection;
use App\Models\Deposit\Deposit;
use App\Models\Deposit\FiatDeposit;
use App\Models\Network\Network;
use App\Models\Option\Option;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use App\Models\Wallet\WalletBalanceLog;
use App\Models\Wallet\WalletAddress;
use App\Models\Withdrawal\FiatWithdrawal;
use App\Models\Withdrawal\Withdrawal;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\FundingFeeDistributionRepository;
use App\Repositories\Launchpad\LaunchpadRepository;
use App\Repositories\Lending\LendingUserRepository;
use App\Repositories\Order\OrderRepository;
use App\Repositories\Option\OptionRepository;
use App\Repositories\Staking\StakingUserRepository;
use App\Repositories\Transaction\FuturesTransactionRepository;
use App\Repositories\Transaction\ReferralTransactionRepository;
use App\Repositories\Transaction\TransactionRepository;
use App\Repositories\Wallet\TransferCommissionRepository;
use App\Repositories\Wallet\WalletTransferRecordRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\FiatWithdrawalRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;

class ReportController extends Controller
{
    public function bonuses()
    {
        $repository = new \App\Repositories\Bonus\DepositBonusRepository();
        $bonuses = $repository->getReport();

        return Inertia::render('Admin/Reports/Bonuses', [
            'filters' => request()->all(['search']),
            'bonuses' => $bonuses,
        ]);
    }

    public function walletAdjustments()
    {
        $repository = new \App\Repositories\Wallet\WalletAdjustmentRepository();
        $records = $repository->getReport();

        return Inertia::render('Admin/Reports/WalletAdjustments', [
            'filters' => request()->all(['search', 'referrer']),
            'records' => $records,
        ]);
    }

    public function walletBalanceLogs()
    {
        $perPage = (int) request()->get('per_page', 10);

        if (!in_array($perPage, [10, 25, 50, 100, 200], true)) {
            $perPage = 10;
        }

        $records = $this->walletBalanceLogsQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Admin/Reports/WalletBalanceLogs', [
            'filters' => request()->all(['search', 'referrer', 'user_id', 'referral', 'period', 'account_field', 'change_type', 'currency', 'per_page']),
            'records' => $records,
            'accountFields' => $this->walletBalanceAccountFields(),
        ]);
    }

    public function walletBalanceLogsExport()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, [
                'ID',
                'User Email',
                'User ID',
                'Currency',
                'Wallet ID',
                'Account',
                'Change Type',
                'Amount',
                'Balance Before',
                'Balance After',
                'Operation',
                'Actor',
                'Date',
            ]);

            $this->walletBalanceLogsQuery()
                ->orderByDesc('id')
                ->chunk(1000, function ($rows) use ($out) {
                    foreach ($rows as $record) {
                        fputcsv($out, [
                            $record->id,
                            optional($record->user)->email,
                            $record->user_id,
                            optional($record->currency)->symbol,
                            $record->wallet_id,
                            $record->account_label,
                            $record->change_type,
                            $this->formatWalletBalanceLogAmount($record->amount),
                            $this->formatWalletBalanceLogAmount($record->balance_before),
                            $this->formatWalletBalanceLogAmount($record->balance_after),
                            $record->operation,
                            trim(($record->actor_email ?: '').($record->actor_user_id ? ' (ID: '.$record->actor_user_id.')' : '')),
                            $record->created_at,
                        ]);
                    }
                });

            fclose($out);
        }, 'admin_wallet_balance_logs_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    protected function walletBalanceLogsQuery()
    {
        $query = WalletBalanceLog::query()
            ->with(['user', 'currency', 'wallet']);

        app(\App\Services\Admin\AdminGroupFilterService::class)
            ->applyToQuery($query, 'user_id');

        $query->whereNotIn('account_field', $this->walletBalanceVirtualAccountFields());
        $query->whereHas('user', function ($query) {
            $query->where('is_xn', false)
                ->orWhereNull('is_xn');
        });

        return $this->applyWalletBalanceLogFilters($query, request()->only([
            'search',
            'referrer',
            'user_id',
            'referral',
            'period',
            'account_field',
            'change_type',
            'currency',
        ]));
    }

    protected function applyWalletBalanceLogFilters($query, array $filters)
    {
        $likeOperator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        $query->when($filters['search'] ?? null, function ($query, $search) use ($likeOperator) {
            $query->where(function ($query) use ($search, $likeOperator) {
                if (is_numeric($search)) {
                    $query->orWhere('id', (int) $search)
                        ->orWhere('user_id', (int) $search)
                        ->orWhere('wallet_id', (int) $search)
                        ->orWhere('actor_user_id', (int) $search);
                }

                $query->orWhere('account_field', $likeOperator, '%'.$search.'%')
                    ->orWhere('account_label', $likeOperator, '%'.$search.'%')
                    ->orWhere('operation', $likeOperator, '%'.$search.'%')
                    ->orWhere('source', $likeOperator, '%'.$search.'%')
                    ->orWhere('route_name', $likeOperator, '%'.$search.'%')
                    ->orWhere('request_path', $likeOperator, '%'.$search.'%')
                    ->orWhere('actor_email', $likeOperator, '%'.$search.'%')
                    ->orWhereHas('user', function ($query) use ($search, $likeOperator) {
                        $query->where('email', $likeOperator, '%'.$search.'%')
                            ->orWhere('name', $likeOperator, '%'.$search.'%')
                            ->orWhere('nickname', $likeOperator, '%'.$search.'%')
                            ->orWhere('referral_code', $likeOperator, '%'.$search.'%');
                    })
                    ->orWhereHas('currency', function ($query) use ($search, $likeOperator) {
                        $query->where('symbol', $likeOperator, '%'.$search.'%')
                            ->orWhere('name', $likeOperator, '%'.$search.'%');
                    });
            });
        });

        $query->when($filters['referrer'] ?? null, function ($query, $referrer) {
            $query->where('user_id', $referrer);
        });

        $query->when($filters['user_id'] ?? null, function ($query, $userId) {
            $query->where('user_id', $userId);
        });

        $query->when($filters['referral'] ?? null, function ($query, $referral) use ($likeOperator) {
            $query->whereHas('user', function ($query) use ($referral, $likeOperator) {
                $query->where('referral_code', $likeOperator, '%'.$referral.'%')
                    ->orWhereHas('referral', function ($query) use ($referral, $likeOperator) {
                        $query->where('email', $likeOperator, '%'.$referral.'%')
                            ->orWhere('name', $likeOperator, '%'.$referral.'%')
                            ->orWhere('nickname', $likeOperator, '%'.$referral.'%')
                            ->orWhere('referral_code', $likeOperator, '%'.$referral.'%');
                    });

                if (is_numeric($referral)) {
                    $query->orWhere('referral_id', (int) $referral);
                }
            });
        });

        $query->when($filters['account_field'] ?? null, function ($query, $accountField) {
            $query->where('account_field', $accountField);
        });

        $query->when($filters['change_type'] ?? null, function ($query, $changeType) {
            $query->where('change_type', $changeType);
        });

        $query->when($filters['currency'] ?? null, function ($query, $currency) use ($likeOperator) {
            $query->whereHas('currency', function ($query) use ($currency, $likeOperator) {
                if (is_numeric($currency)) {
                    $query->where('id', (int) $currency)
                        ->orWhere('symbol', $likeOperator, '%'.$currency.'%');

                    return;
                }

                $query->where('symbol', $likeOperator, '%'.$currency.'%')
                    ->orWhere('name', $likeOperator, '%'.$currency.'%');
            });
        });

        [$start, $end] = $this->walletBalanceLogPeriodBounds($filters['period'] ?? []);

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return $query;
    }

    protected function walletBalanceLogPeriodBounds($period): array
    {
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

        return [$start, $end];
    }

    protected function walletBalanceAccountFields(): array
    {
        return [
            ['id' => 'balance_in_wallet', 'name' => '资金账户'],
            ['id' => 'balance_in_trade', 'name' => '交易账户'],
            ['id' => 'balance_in_order', 'name' => '订单冻结'],
            ['id' => 'balance_in_withdraw', 'name' => '提现冻结'],
            ['id' => 'balance_in_lc', 'name' => '理财账户'],
        ];
    }

    protected function walletBalanceVirtualAccountFields(): array
    {
        return [
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
            'balance_in_virtual_withdraw',
        ];
    }

    protected function formatWalletBalanceLogAmount($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    public function index()
    {
        if ($this->currentUserIsSalesman()) {
            return Redirect::route('admin.reports.futures.active');
        }

        return Redirect::route('admin.reports.wallets.system');
    }

    protected function resolveLeaderDisplayNameForDepositUser($user, array &$leaderCache = [], array &$userCache = []): string
    {
        return $this->resolveLeaderDisplayName($user, $leaderCache, $userCache);
    }

    protected function findNearestDepositLeader($user, array &$leaderCache = [], array &$userCache = []): ?User
    {
        return $this->findNearestLeader($user, $leaderCache, $userCache);
    }

    protected function formatDepositLeaderName($leader): string
    {
        return $this->formatLeaderName($leader);
    }

    protected function resolveLeaderDisplayNameForWithdrawalUser($user, array &$leaderCache = [], array &$userCache = []): string
    {
        return $this->resolveLeaderDisplayName($user, $leaderCache, $userCache);
    }

    protected function findNearestWithdrawalLeader($user, array &$leaderCache = [], array &$userCache = []): ?User
    {
        return $this->findNearestLeader($user, $leaderCache, $userCache);
    }

    protected function formatWithdrawalLeaderName($leader): string
    {
        return $this->formatLeaderName($leader);
    }

    protected function resolveLeaderDisplayName($user, array &$leaderCache = [], array &$userCache = []): string
    {
        if (!$user) {
            return '';
        }

        $user->loadMissing('roles');

        /*
         * 如果当前用户自己就是组长：
         * 有 leader_nickname 显示昵称；
         * 没有昵称显示账户。
         */
        if ($user->hasRole('user_leader')) {
            return $this->formatLeaderName($user);
        }

        $leader = $this->findNearestLeader($user, $leaderCache, $userCache);

        if (!$leader) {
            return '';
        }

        return $this->formatLeaderName($leader);
    }
    protected function getAllTeamUserIds(int $userId): array
{
    $allIds = [$userId];
    $pendingIds = [$userId];

    while (!empty($pendingIds)) {
        $children = \App\Models\User\User::query()
            ->whereIn('referral_id', $pendingIds)
            ->pluck('id')
            ->toArray();

        $children = array_values(array_diff($children, $allIds));

        if (empty($children)) {
            break;
        }

        $allIds = array_merge($allIds, $children);
        $pendingIds = $children;
    }

    return array_unique($allIds);
}
public function feeRefunds()
{
    $currentUser = auth()->user();

    $type = request()->get('type');
    $feeType = request()->get('fee_type');
    $search = trim((string) request()->get('search', ''));

    $userId = request()->get('user_id');
    $email = trim((string) request()->get('email', ''));
    $walletId = request()->get('wallet_id');
    $currencyId = request()->get('currency_id');
    $marketId = request()->get('market_id');
    $vipLevel = request()->get('vip_level');

    $originalFeeMin = request()->get('original_fee_min');
    $originalFeeMax = request()->get('original_fee_max');
    $refundAmountMin = request()->get('refund_amount_min');
    $refundAmountMax = request()->get('refund_amount_max');
    $refundRateMin = request()->get('refund_rate_min');
    $refundRateMax = request()->get('refund_rate_max');
    $discountRateMin = request()->get('discount_rate_min');
    $discountRateMax = request()->get('discount_rate_max');

    $period = request()->get('period', []);
    $perPage = (int) request()->get('per_page', 10);

    if (!in_array($perPage, [10, 50, 100, 500])) {
        $perPage = 10;
    }

    $queries = [];

    /*
     * 现货手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('spot_fee_refund_records')) {
        $spotQuery = \Illuminate\Support\Facades\DB::table('spot_fee_refund_records')
            ->leftJoin('users', 'users.id', '=', 'spot_fee_refund_records.user_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'spot_fee_refund_records.currency_id')
            ->leftJoin('markets', 'markets.id', '=', 'spot_fee_refund_records.market_id')
            ->selectRaw("
                CAST(spot_fee_refund_records.id AS TEXT) as id,
                'spot' as product_type,
                spot_fee_refund_records.user_id,
                users.email as user_email,
                spot_fee_refund_records.wallet_id,
                CAST(spot_fee_refund_records.transaction_id AS TEXT) as source_id,
                CAST(spot_fee_refund_records.order_id AS TEXT) as order_id,
                spot_fee_refund_records.market_id,
                CAST(markets.name AS TEXT) as market_name,
                spot_fee_refund_records.currency_id,
                CAST(currencies.symbol AS TEXT) as currency_symbol,
                CAST(spot_fee_refund_records.fee_role AS TEXT) as fee_type,
                spot_fee_refund_records.original_fee,
                spot_fee_refund_records.vip_level,
                spot_fee_refund_records.discount_rate,
                spot_fee_refund_records.refund_rate,
                spot_fee_refund_records.refund_amount,
                spot_fee_refund_records.created_at,
                spot_fee_refund_records.updated_at
            ");

        $queries[] = $spotQuery;
    }

    /*
     * 合约手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('futures_fee_refund_records')) {
        $hasVipLevel = \Illuminate\Support\Facades\Schema::hasColumn('futures_fee_refund_records', 'vip_level');
        $hasDiscountRate = \Illuminate\Support\Facades\Schema::hasColumn('futures_fee_refund_records', 'discount_rate');

        $vipLevelSelect = $hasVipLevel
            ? 'futures_fee_refund_records.vip_level'
            : 'CAST(0 AS INTEGER) as vip_level';

        $discountRateSelect = $hasDiscountRate
            ? 'futures_fee_refund_records.discount_rate'
            : 'CAST(1 AS NUMERIC) as discount_rate';

        $futuresQuery = \Illuminate\Support\Facades\DB::table('futures_fee_refund_records')
            ->leftJoin('users', 'users.id', '=', 'futures_fee_refund_records.user_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'futures_fee_refund_records.currency_id')
            ->leftJoin('markets', 'markets.id', '=', 'futures_fee_refund_records.market_id')
            ->selectRaw("
                CAST(futures_fee_refund_records.id AS TEXT) as id,
                'futures' as product_type,
                futures_fee_refund_records.user_id,
                users.email as user_email,
                futures_fee_refund_records.wallet_id,
                CAST(futures_fee_refund_records.future_contract_id AS TEXT) as source_id,
                CAST(futures_fee_refund_records.future_contract_id AS TEXT) as order_id,
                futures_fee_refund_records.market_id,
                CAST(markets.name AS TEXT) as market_name,
                futures_fee_refund_records.currency_id,
                CAST(currencies.symbol AS TEXT) as currency_symbol,
                CAST(futures_fee_refund_records.fee_type AS TEXT) as fee_type,
                futures_fee_refund_records.original_fee,
                {$vipLevelSelect},
                {$discountRateSelect},
                futures_fee_refund_records.refund_rate,
                futures_fee_refund_records.refund_amount,
                futures_fee_refund_records.created_at,
                futures_fee_refund_records.updated_at
            ");

        $queries[] = $futuresQuery;
    }

    /*
     * 期权手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('option_fee_refund_records')) {
        $optionQuery = \Illuminate\Support\Facades\DB::table('option_fee_refund_records')
            ->leftJoin('users', 'users.id', '=', 'option_fee_refund_records.user_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'option_fee_refund_records.currency_id')
            ->leftJoin('markets', 'markets.id', '=', 'option_fee_refund_records.market_id')
            ->selectRaw("
                CAST(option_fee_refund_records.id AS TEXT) as id,
                'option' as product_type,
                option_fee_refund_records.user_id,
                users.email as user_email,
                option_fee_refund_records.wallet_id,
                CAST(option_fee_refund_records.option_uuid AS TEXT) as source_id,
                CAST(option_fee_refund_records.option_uuid AS TEXT) as order_id,
                option_fee_refund_records.market_id,
                CAST(markets.name AS TEXT) as market_name,
                option_fee_refund_records.currency_id,
                CAST(currencies.symbol AS TEXT) as currency_symbol,
                'entry' as fee_type,
                option_fee_refund_records.original_fee,
                option_fee_refund_records.vip_level,
                option_fee_refund_records.discount_rate,
                option_fee_refund_records.refund_rate,
                option_fee_refund_records.refund_amount,
                option_fee_refund_records.created_at,
                option_fee_refund_records.updated_at
            ");

        $queries[] = $optionQuery;
    }

    /*
     * 转账手续费返还
     */
    if (\Illuminate\Support\Facades\Schema::hasTable('transfer_fee_refund_records')) {
        $transferQuery = \Illuminate\Support\Facades\DB::table('transfer_fee_refund_records')
            ->leftJoin('users', 'users.id', '=', 'transfer_fee_refund_records.user_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'transfer_fee_refund_records.currency_id')
            ->selectRaw("
                CAST(transfer_fee_refund_records.id AS TEXT) as id,
                'transfer' as product_type,
                transfer_fee_refund_records.user_id,
                users.email as user_email,
                transfer_fee_refund_records.wallet_id,
                COALESCE(CAST(transfer_fee_refund_records.commission_record_id AS TEXT), CAST(transfer_fee_refund_records.id AS TEXT)) as source_id,
                COALESCE(CAST(transfer_fee_refund_records.commission_record_id AS TEXT), CAST(transfer_fee_refund_records.id AS TEXT)) as order_id,
                CAST(NULL AS BIGINT) as market_id,
                CAST(NULL AS TEXT) as market_name,
                transfer_fee_refund_records.currency_id,
                CAST(currencies.symbol AS TEXT) as currency_symbol,
                CAST(transfer_fee_refund_records.direction AS TEXT) as fee_type,
                transfer_fee_refund_records.original_fee,
                transfer_fee_refund_records.vip_level,
                transfer_fee_refund_records.discount_rate,
                transfer_fee_refund_records.refund_rate,
                transfer_fee_refund_records.refund_amount,
                transfer_fee_refund_records.created_at,
                transfer_fee_refund_records.updated_at
            ");

        $queries[] = $transferQuery;
    }

    if (empty($queries)) {
        $records = new \Illuminate\Pagination\LengthAwarePaginator(
            [],
            0,
            $perPage,
            request()->get('page', 1),
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        return Inertia::render('Admin/Reports/FeeRefunds', [
            'records' => $records,
            'filters' => request()->all(),
            'total_refund_amount' => '0.00000000',
            'total_original_fee' => '0.00000000',
            'total_records' => 0,
        ]);
    }

    $unionQuery = array_shift($queries);

    foreach ($queries as $query) {
        $unionQuery->unionAll($query);
    }

    $recordsQuery = \Illuminate\Support\Facades\DB::query()
        ->fromSub($unionQuery, 'fee_refunds');

    app(\App\Services\Admin\AdminGroupFilterService::class)
        ->applyToQuery($recordsQuery, 'user_id');

    /*
     * 管理员数据权限
     * superadmin 看全部
     * admin / finance_manager / user_leader / salesman 看自己团队
     */
    $currentUser->loadMissing('roles');
    $roleIds = $currentUser->roles
        ->pluck('id')
        ->map(function ($id) {
            return (int) $id;
        })
        ->toArray();

    $roleNames = $currentUser->roles
        ->pluck('name')
        ->filter()
        ->values()
        ->toArray();

    $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);
    $hasTeamDataScope = count(array_intersect($roleNames, [
        'admin',
        'finance_manager',
        'user_leader',
        'salesman',
        'perm_finances',
    ])) > 0 || (auth()->user()?->hasRole('admin') ?? false);

    if (!$isSuperAdmin) {
        if ($hasTeamDataScope) {
            $teamUserIds = $this->getAllTeamUserIds($currentUser->id);

            if (empty($teamUserIds)) {
                $recordsQuery->whereRaw('1 = 0');
            } else {
                $recordsQuery->whereIn('user_id', $teamUserIds);
            }
        } else {
            $recordsQuery->whereRaw('1 = 0');
        }
    }

    if (!empty($type) && in_array($type, ['spot', 'futures', 'option', 'transfer'])) {
        $recordsQuery->where('product_type', $type);
    }

    if (!empty($feeType)) {
        $recordsQuery->where('fee_type', $feeType);
    }

    if ($search !== '') {
        $recordsQuery->where(function ($query) use ($search) {
            $keyword = '%' . $search . '%';

            $query->whereRaw('source_id ILIKE ?', [$keyword])
                ->orWhereRaw('order_id ILIKE ?', [$keyword])
                ->orWhereRaw('user_email ILIKE ?', [$keyword])
                ->orWhereRaw('currency_symbol ILIKE ?', [$keyword])
                ->orWhereRaw('market_name ILIKE ?', [$keyword])
                ->orWhereRaw('CAST(user_id AS TEXT) ILIKE ?', [$keyword])
                ->orWhereRaw('CAST(wallet_id AS TEXT) ILIKE ?', [$keyword])
                ->orWhereRaw('CAST(currency_id AS TEXT) ILIKE ?', [$keyword])
                ->orWhereRaw('CAST(market_id AS TEXT) ILIKE ?', [$keyword]);
        });
    }

    if (!empty($userId)) {
        $recordsQuery->where('user_id', $userId);
    }

    if ($email !== '') {
        $recordsQuery->whereRaw('user_email ILIKE ?', ['%' . $email . '%']);
    }

    if (!empty($walletId)) {
        $recordsQuery->where('wallet_id', $walletId);
    }

    if (!empty($currencyId)) {
        $recordsQuery->where('currency_id', $currencyId);
    }

    if (!empty($marketId)) {
        $recordsQuery->where('market_id', $marketId);
    }

    if ($vipLevel !== null && $vipLevel !== '') {
        $recordsQuery->where('vip_level', $vipLevel);
    }

    if ($originalFeeMin !== null && $originalFeeMin !== '') {
        $recordsQuery->where('original_fee', '>=', $originalFeeMin);
    }

    if ($originalFeeMax !== null && $originalFeeMax !== '') {
        $recordsQuery->where('original_fee', '<=', $originalFeeMax);
    }

    if ($refundAmountMin !== null && $refundAmountMin !== '') {
        $recordsQuery->where('refund_amount', '>=', $refundAmountMin);
    }

    if ($refundAmountMax !== null && $refundAmountMax !== '') {
        $recordsQuery->where('refund_amount', '<=', $refundAmountMax);
    }

    if ($refundRateMin !== null && $refundRateMin !== '') {
        $recordsQuery->where('refund_rate', '>=', $refundRateMin);
    }

    if ($refundRateMax !== null && $refundRateMax !== '') {
        $recordsQuery->where('refund_rate', '<=', $refundRateMax);
    }

    if ($discountRateMin !== null && $discountRateMin !== '') {
        $recordsQuery->where('discount_rate', '>=', $discountRateMin);
    }

    if ($discountRateMax !== null && $discountRateMax !== '') {
        $recordsQuery->where('discount_rate', '<=', $discountRateMax);
    }

    if (!empty($period) && is_array($period) && count($period) === 2 && !empty($period[0]) && !empty($period[1])) {
        $recordsQuery->whereBetween('created_at', [
            $period[0] . ' 00:00:00',
            $period[1] . ' 23:59:59',
        ]);
    }

    $totalRefundAmount = (clone $recordsQuery)->sum('refund_amount');
    $totalOriginalFee = (clone $recordsQuery)->sum('original_fee');
    $totalRecords = (clone $recordsQuery)->count();

    $records = $recordsQuery
        ->orderByDesc('created_at')
        ->paginate($perPage)
        ->withQueryString();

    return Inertia::render('Admin/Reports/FeeRefunds', [
        'records' => $records,
        'filters' => request()->all(),
        'total_refund_amount' => number_format((float) $totalRefundAmount, 8, '.', ''),
        'total_original_fee' => number_format((float) $totalOriginalFee, 8, '.', ''),
        'total_records' => $totalRecords,
    ]);
}
    protected function findNearestLeader($user, array &$leaderCache = [], array &$userCache = []): ?User
    {
        if (!$user) {
            return null;
        }

        $userId = (int) $user->id;

        if (array_key_exists($userId, $leaderCache)) {
            return $leaderCache[$userId];
        }

        $referralId = $user->referral_id ?? null;
        $visited = [];
        $depth = 0;

        while ($referralId && $depth < 50) {
            $referralId = (int) $referralId;

            if (in_array($referralId, $visited, true)) {
                break;
            }

            $visited[] = $referralId;

            if (array_key_exists($referralId, $userCache)) {
                $parent = $userCache[$referralId];
            } else {
                $parent = User::query()
                    ->with('roles')
                    ->select([
                        'id',
                        'name',
                        'email',
                        'wallet_id',
                        'referral_id',
                        'leader_nickname',
                    ])
                    ->where('id', $referralId)
                    ->first();

                $userCache[$referralId] = $parent;
            }

            if (!$parent) {
                break;
            }

            if ($parent->hasRole('user_leader')) {
                $leaderCache[$userId] = $parent;

                return $parent;
            }

            $referralId = $parent->referral_id ?? null;
            $depth++;
        }

        $leaderCache[$userId] = null;

        return null;
    }

    protected function formatLeaderName($leader): string
    {
        if (!$leader) {
            return '';
        }

        $leaderNickname = trim((string) ($leader->leader_nickname ?? ''));

        if ($leaderNickname !== '') {
            return $leaderNickname;
        }

        $email = trim((string) ($leader->email ?? ''));

        if ($email !== '') {
            return $email;
        }

        $walletId = trim((string) ($leader->wallet_id ?? ''));

        if ($walletId !== '') {
            return $walletId;
        }

        $name = trim((string) ($leader->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return '';
    }

public function deposits()
{
    $perPage = \App\Support\AdminReportFilters::perPage();

    request()->merge([
        'per_page' => $perPage,
    ]);

    $depositRepository = new DepositRepository();

    $deposits = $depositRepository->getReport();

    $leaderCache = [];
    $userCache = [];
    $referrerChainUserCache = [];

    /*
     * 当前页面充值总额，统一折算成 USDT。
     * 非 USDT / USDC 使用 deposits.usdt_rate 锁定价格。
     */
    $totalDepositAmountUsdt = '0';
    $totalDepositCount = 0;
    $totalDepositUserIds = [];

    $deposits->getCollection()->transform(function ($deposit) use (
        &$leaderCache,
        &$userCache,
        &$referrerChainUserCache,
        &$totalDepositAmountUsdt,
        &$totalDepositCount,
        &$totalDepositUserIds
    ) {
        $leaderDisplayName = '';
        $referrerDisplayName = '';
        $referrerChain = [];
        $isPlatformInternalTransfer = $this->isPlatformInternalTransferDeposit($deposit);
        $internalTransferUid = $this->platformInternalTransferUid($deposit);

        /*
         * 单条充值金额折算成 USDT。
         *
         * 规则：
         * 1. USDT / USDC 固定 1:1。
         * 2. 其他代币第一次加载时，把 markets.last 存到 deposits.usdt_rate。
         * 3. 以后永远使用 deposits.usdt_rate 计算，不再跟随行情变化。
         */
        $currencyId = (int) ($deposit->currency_id ?? optional($deposit->currency)->id ?? 0);
        $amount = $deposit->amount ?? 0;

        $lockedRate = $this->getDepositLockedUsdtRate($deposit, $currencyId);

        $amountUsdt = '0';

        if (
            is_numeric($amount) &&
            (float) $amount > 0 &&
            is_numeric($lockedRate) &&
            (float) $lockedRate > 0
        ) {
            /*
             * 内部计算可以先保留高精度，最后展示统一保留 2 位。
             */
            $amountUsdt = math_multiply($amount, $lockedRate);
        }

        /*
         * 汇率继续保留 18 位，避免历史锁价精度丢失。
         * 金额展示全部保留 2 位小数。
         */
        $deposit->usdt_rate = math_formatter($lockedRate, 18, '.', '');

        $deposit->amount_usdt = math_formatter($amountUsdt, 2, '.', '');
        $deposit->amount_usdt_display = math_formatter($amountUsdt, 2);

        if ($this->shouldIncludeDepositInCurrentStats($deposit)) {
            $totalDepositCount++;

            if ($deposit->user_id) {
                $totalDepositUserIds[] = (int) $deposit->user_id;
            }

            $totalDepositAmountUsdt = math_sum($totalDepositAmountUsdt, $amountUsdt);
        }

        if ($deposit->user) {
            $leaderDisplayName = $this->resolveLeaderDisplayNameForDepositUser(
                $deposit->user,
                $leaderCache,
                $userCache
            );

            $referrerDisplayName = $this->resolveReferrerDisplayNameForDepositUser(
                $deposit->user,
                $userCache
            );

            /*
             * 获取当前用户的所有上级推荐人。
             * 顺序：直属上级 -> 上上级 -> 更上级。
             */
            $referrerChain = $this->buildDepositUserReferrerChain(
                $deposit->user,
                $referrerChainUserCache
            );

            $deposit->user->leader_display_name = $leaderDisplayName;
            $deposit->user->referrer_display_name = $referrerDisplayName;
            $deposit->user->referrer_chain = $referrerChain;
        }

        $deposit->leader_display_name = $leaderDisplayName;
        $deposit->referrer_display_name = $referrerDisplayName;
        $deposit->referrer_chain = $referrerChain;
        $deposit->is_platform_internal_transfer = $isPlatformInternalTransfer;
        $deposit->source_label = $isPlatformInternalTransfer ? '站内转账' : null;
        $deposit->network_display = $isPlatformInternalTransfer ? '站内转账' : optional($deposit->network)->name;
	        $deposit->address_display = $isPlatformInternalTransfer
	            ? ($internalTransferUid ? '站内转账 - 来自UID:' . $internalTransferUid : '站内转账')
	            : $deposit->address;

        $this->attachAdminDepositDisplayTimes($deposit);

	        return $deposit;
    });

    $currencies = new CurrencyLiteCollection(
        (new CurrencyRepository())->all(false, false, [], 'coin')
    );

    $networks = Network::query()
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

    return Inertia::render('Admin/Reports/Deposits', [
        'filters' => request()->all([
            'search',
            'type',
            'status',
            'network_id',
            'user_id',
            'txn',
            'address',
            'period',
            'referrer',
            'team_user_id',
            'per_page',
            'record_type',
            'first_only',
        ]),
        'deposits' => $deposits,
        'currencies' => $currencies,
        'networks' => $networks,

        /*
         * 当前分页展示出来的数据合计。
         * 已使用 deposits.usdt_rate 锁价计算。
         * 金额统一保留 2 位小数。
         */
        'stats' => [
            'deposit_count' => $totalDepositCount,
            'deposit_user_count' => count(array_unique($totalDepositUserIds)),
            'total_deposit_amount_usdt' => math_formatter($totalDepositAmountUsdt, 2, '.', ''),
            'totalDepositAmountUsdt' => math_formatter($totalDepositAmountUsdt, 2, '.', ''),
        ],
    ]);
}

protected function isPlatformInternalTransferDeposit($deposit): bool
{
    if (!$deposit || ($deposit->source_id ?? null) !== DepositRepository::PLATFORM_INTERNAL_TRANSFER_SOURCE) {
        return false;
    }

    $raw = $this->decodeDepositRawMeta($deposit->initial_raw ?? null);

    if (empty($raw)) {
        $raw = $this->decodeDepositRawMeta($deposit->raw ?? null);
    }

    if (($raw['source'] ?? null) === 'platform_internal_transfer') {
        return true;
    }

    return str_starts_with((string) ($deposit->address ?? ''), '内部转账 - 来自UID:');
}

protected function platformInternalTransferUid($deposit): string
{
    $raw = $this->decodeDepositRawMeta($deposit->initial_raw ?? null);

    if (empty($raw)) {
        $raw = $this->decodeDepositRawMeta($deposit->raw ?? null);
    }

    $uid = trim((string) ($raw['from_referral_code'] ?? $deposit->internal_id ?? ''));

    if ($uid !== '') {
        return $uid;
    }

    $address = (string) ($deposit->address ?? '');

    if (preg_match('/来自UID:([^\\s#]+)/u', $address, $matches)) {
        return trim((string) ($matches[1] ?? ''));
    }

    return '';
}

protected function decodeDepositRawMeta($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_object($value)) {
        return (array) $value;
    }

    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

protected function shouldIncludeDepositInCurrentStats($deposit): bool
{
    $networkName = $deposit->network && $deposit->network->name
        ? strtolower((string) $deposit->network->name)
        : '';

    $networkSlug = $deposit->network && isset($deposit->network->slug)
        ? strtolower((string) $deposit->network->slug)
        : '';

    $address = $deposit->address
        ? strtolower((string) $deposit->address)
        : '';

    if ($networkName === 'internal'
        || $networkSlug === 'internal'
        || $address === 'internal'
        || empty($deposit->amount)
        || !is_numeric($deposit->amount)
        || (float) $deposit->amount <= 0
        || !empty($deposit->internal_id)) {
        return false;
    }

    $symbol = $deposit->currency && $deposit->currency->symbol
        ? strtoupper((string) $deposit->currency->symbol)
        : '';

    $altSymbol = $deposit->currency && $deposit->currency->alt_symbol
        ? strtoupper((string) $deposit->currency->alt_symbol)
        : '';

    if (in_array($symbol, ['USDT', 'USDC'], true) || in_array($altSymbol, ['USDT', 'USDC'], true)) {
        return (float) $deposit->amount >= 1;
    }

    if ($symbol === 'TRX' || $altSymbol === 'TRX') {
        return (float) $deposit->amount >= 5;
    }

    return $networkName !== 'internal'
        && $networkSlug !== 'internal'
        && $address !== 'internal'
        && empty($deposit->internal_id);
}

protected function getDepositLockedUsdtRate($deposit, int $currencyId): string
{
    if ($currencyId <= 0) {
        return '0';
    }

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol', 'alt_symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        return '0';
    }

    /*
     * USDT / USDC 固定 1:1。
     */
    if ($this->isUsdStableDepositCurrency($currency)) {
        $this->saveDepositUsdtRateIfEmpty((int) $deposit->id, '1');

        return '1';
    }

    /*
     * 如果这笔充值已经保存过 usdt_rate，以后就永远使用这个价格。
     */
    $savedRate = $deposit->usdt_rate ?? null;

    if (is_numeric($savedRate) && (float) $savedRate > 0) {
        return math_formatter($savedRate, 18, '.', '');
    }

    /*
     * 第一次加载时，读取当前 markets.last。
     */
    $currentRate = $this->getDepositCurrentUsdtRateByCurrencyId($currencyId);

    if (!is_numeric($currentRate) || (float) $currentRate <= 0) {
        return '0';
    }

    /*
     * 写入 deposits.usdt_rate，以后固定使用。
     */
    $this->saveDepositUsdtRateIfEmpty((int) $deposit->id, $currentRate);

    return math_formatter($currentRate, 18, '.', '');
}

protected function saveDepositUsdtRateIfEmpty(int $depositId, $rate): void
{
    if ($depositId <= 0 || !is_numeric($rate) || (float) $rate <= 0) {
        return;
    }

    /*
     * 只在 usdt_rate 为空或小于等于 0 时写入。
     * 已经有值的历史记录不覆盖，避免价格被刷新。
     */
    \Illuminate\Support\Facades\DB::table('deposits')
        ->where('id', $depositId)
        ->where(function ($query) {
            $query->whereNull('usdt_rate')
                ->orWhere('usdt_rate', '<=', 0);
        })
        ->update([
            'usdt_rate' => math_formatter($rate, 18, '.', ''),
        ]);
}

protected function getDepositCurrentUsdtRateByCurrencyId(int $currencyId): string
{
    static $rateCache = [];

    if ($currencyId <= 0) {
        return '0';
    }

    if (isset($rateCache[$currencyId])) {
        return $rateCache[$currencyId];
    }

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol', 'alt_symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    if ($this->isUsdStableDepositCurrency($currency)) {
        $rateCache[$currencyId] = '1';
        return '1';
    }

    $usdt = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol'])
        ->whereRaw('UPPER(symbol) = ?', ['USDT'])
        ->first();

    $usd = \Illuminate\Support\Facades\DB::table('currencies')
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
     *
     * markets.last 就是这个代币的价格。
     */
    $directQuery = \Illuminate\Support\Facades\DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->where('base_currency_id', $currencyId)
        ->whereIn('quote_currency_id', $quoteIds)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $directQuery->orderByRaw('CASE WHEN quote_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END');
    }

    $directMarket = $directQuery->first();

    if ($directMarket && is_numeric($directMarket->last) && (float) $directMarket->last > 0) {
        $rateCache[$currencyId] = math_formatter($directMarket->last, 18, '.', '');
        return $rateCache[$currencyId];
    }

    /*
     * 反向交易对：
     * USDT / 当前币种 或 USD / 当前币种
     *
     * 当前币种转 USDT 汇率 = 1 / markets.last。
     */
    $reverseQuery = \Illuminate\Support\Facades\DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->whereIn('base_currency_id', $quoteIds)
        ->where('quote_currency_id', $currencyId)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $reverseQuery->orderByRaw('CASE WHEN base_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END');
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

    /*
     * 找不到价格时不乱算。
     */
    $rateCache[$currencyId] = '0';

    return '0';
}

protected function formatDepositUsdtAmount($value): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    $value = str_replace(',', '', (string) $value);

    if (!is_numeric($value)) {
        return '0';
    }

    $formatted = math_formatter($value, 2);

    if (strpos($formatted, '.') !== false) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted === '' ? '0' : $formatted;
}
protected function convertDepositAmountToUsdt($amount, int $currencyId): string
{
    if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0) {
        return '0';
    }

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol', 'alt_symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        return '0';
    }

    /*
     * USDT / USDC / USD 直接按 1:1。
     */
    if ($this->isUsdStableDepositCurrency($currency)) {
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

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol', 'alt_symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    if ($this->isUsdStableDepositCurrency($currency)) {
        $rateCache[$currencyId] = '1';
        return '1';
    }

    $usdt = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol'])
        ->whereRaw('UPPER(symbol) = ?', ['USDT'])
        ->first();

    $usd = \Illuminate\Support\Facades\DB::table('currencies')
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
     * 当前币种 / USDT 或 当前币种 / USD。
     *
     * markets.last 就是这个代币的价格。
     */
    $directQuery = \Illuminate\Support\Facades\DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->where('base_currency_id', $currencyId)
        ->whereIn('quote_currency_id', $quoteIds)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $directQuery->orderByRaw('CASE WHEN quote_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END');
    }

    $directMarket = $directQuery->first();

    if ($directMarket && is_numeric($directMarket->last) && (float) $directMarket->last > 0) {
        $rateCache[$currencyId] = math_formatter($directMarket->last, 18, '.', '');
        return $rateCache[$currencyId];
    }

    /*
     * 反向交易对：
     * USDT / 当前币种 或 USD / 当前币种。
     *
     * 当前币种转 USDT 汇率 = 1 / markets.last。
     */
    $reverseQuery = \Illuminate\Support\Facades\DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->whereIn('base_currency_id', $quoteIds)
        ->where('quote_currency_id', $currencyId)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $reverseQuery->orderByRaw('CASE WHEN base_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END');
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

    /*
     * 找不到行情时，不乱算。
     */
    $rateCache[$currencyId] = '0';

    return '0';
}

protected function isUsdStableDepositCurrencySymbol(string $symbol): bool
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

protected function isUsdStableDepositCurrency($currency): bool
{
    return $this->isUsdStableDepositCurrencySymbol((string) ($currency->symbol ?? ''))
        || $this->isUsdStableDepositCurrencySymbol((string) ($currency->alt_symbol ?? ''));
}


private function buildDepositUserReferrerChain($user, array &$userCache): array
{
    $chain = [];
    $visited = [];

    $currentReferrerId = $this->getDepositUserReferrerId($user);
    $level = 1;

    while ($currentReferrerId && $level <= 50) {
        $currentReferrerId = (int) $currentReferrerId;

        if ($currentReferrerId <= 0) {
            break;
        }

        if (in_array($currentReferrerId, $visited, true)) {
            break;
        }

        $visited[] = $currentReferrerId;

        $referrer = $this->getDepositUserByIdFromCache($currentReferrerId, $userCache);

        if (!$referrer) {
            break;
        }

        $chain[] = [
            'level' => $level,
            'id' => (int) $this->getDepositUserValue($referrer, 'id', 0),
            'account' => $this->getDepositUserAccountText($referrer),
            'nickname' => $this->getDepositUserNicknameText($referrer),
            'name' => $this->getDepositUserNameText($referrer),
            'referral_code' => $this->getDepositUserValue($referrer, 'referral_code', ''),
        ];

        $currentReferrerId = $this->getDepositUserReferrerId($referrer);
        $level++;
    }

    return $chain;
}

private function warmDepositUserReferrerCacheForUsers(array $users, array &$userCache): void
{
    $nextReferrerIds = [];
    $expandedUserIds = [];

    foreach ($users as $user) {
        $userId = (int) $this->getDepositUserValue($user, 'id', 0);

        if ($userId > 0) {
            $userCache[$userId] = $user;
        }

        $referrerId = (int) $this->getDepositUserReferrerId($user);

        if ($referrerId > 0) {
            $nextReferrerIds[] = $referrerId;
        }
    }

    for ($level = 1; $level <= 50; $level++) {
        $ids = array_values(array_unique(array_filter(
            $nextReferrerIds,
            function ($id) use (&$expandedUserIds) {
                $id = (int) $id;

                return $id > 0 && !isset($expandedUserIds[$id]);
            }
        )));

        if (empty($ids)) {
            break;
        }

        $missingIds = array_values(array_filter($ids, function ($id) use (&$userCache) {
            return !array_key_exists((int) $id, $userCache);
        }));

        if (!empty($missingIds)) {
            $rows = \Illuminate\Support\Facades\DB::table('users')
                ->whereIn('id', $missingIds)
                ->get()
                ->keyBy('id');

            foreach ($missingIds as $id) {
                $id = (int) $id;
                $userCache[$id] = $rows->get($id);
            }
        }

        $nextReferrerIds = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            $expandedUserIds[$id] = true;
            $row = $userCache[$id] ?? null;

            if (!$row) {
                continue;
            }

            $referrerId = (int) $this->getDepositUserReferrerId($row);

            if ($referrerId > 0) {
                $nextReferrerIds[] = $referrerId;
            }
        }
    }
}

private function getDepositUserByIdFromCache($userId, array &$userCache)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        return null;
    }

    if (!array_key_exists($userId, $userCache)) {
        $userCache[$userId] = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $userId)
            ->first();
    }

    return $userCache[$userId];
}

private function getDepositUserReferrerId($user)
{
    if (!$user) {
        return null;
    }

    $fields = [
        'referral_id',
        'referrer_id',
        'parent_id',
        'pid',
        'invite_user_id',
        'inviter_id',
    ];

    foreach ($fields as $field) {
        $value = $this->getDepositUserValue($user, $field);

        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return null;
}

private function getDepositUserAccountText($user): string
{
    if (!$user) {
        return '-';
    }

    $fields = [
        'email',
        'phone',
        'username',
        'account',
    ];

    foreach ($fields as $field) {
        $value = $this->getDepositUserValue($user, $field);

        if ($value !== null && $value !== '') {
            return (string) $value;
        }
    }

    return '-';
}

private function getDepositUserNicknameText($user): string
{
    if (!$user) {
        return '-';
    }

    $value = $this->getDepositUserValue($user, 'nickname');

    if ($value !== null && $value !== '') {
        return (string) $value;
    }

    return '-';
}

private function getDepositUserNameText($user): string
{
    if (!$user) {
        return '-';
    }

    $fields = [
        'name',
        'real_name',
        'full_name',
    ];

    foreach ($fields as $field) {
        $value = $this->getDepositUserValue($user, $field);

        if ($value !== null && $value !== '') {
            return (string) $value;
        }
    }

    $firstName = $this->getDepositUserValue($user, 'first_name', '');
    $lastName = $this->getDepositUserValue($user, 'last_name', '');

    $fullName = trim((string) $firstName . ' ' . (string) $lastName);

    if ($fullName !== '') {
        return $fullName;
    }

    return $this->getDepositUserAccountText($user);
}

private function getDepositUserValue($user, string $field, $default = null)
{
    if (!$user) {
        return $default;
    }

    /*
     * 兼容 Eloquent Model。
     */
    if (is_object($user) && method_exists($user, 'getAttribute')) {
        $value = $user->getAttribute($field);

        return $value !== null ? $value : $default;
    }

    /*
     * 兼容 DB::table 查询出来的 stdClass。
     */
    if (is_object($user) && property_exists($user, $field)) {
        return $user->{$field};
    }

    /*
     * 兼容数组。
     */
    if (is_array($user) && array_key_exists($field, $user)) {
        return $user[$field];
    }

    return $default;
}
protected function resolveReferrerDisplayNameForDepositUser($user, array &$userCache): string
{
    if (!$user || empty($user->referral_id)) {
        return '-';
    }

    $referrerId = (int) $user->referral_id;

    if ($referrerId <= 0) {
        return '-';
    }

    if (!array_key_exists($referrerId, $userCache)) {
        $userCache[$referrerId] = \App\Models\User\User::query()
            ->select('id', 'email', 'nickname', 'referral_code')
            ->where('id', $referrerId)
            ->first();
    }

    $referrer = $userCache[$referrerId];

    if (!$referrer) {
        return '-';
    }

    if (!empty($referrer->nickname)) {
        return $referrer->nickname;
    }

    if (!empty($referrer->email)) {
        return $referrer->email;
    }

    if (!empty($referrer->referral_code)) {
        return $referrer->referral_code;
    }

    return 'ID: ' . $referrer->id;
}
    public function exportDeposits()
    {
        $depositRepository = new DepositRepository();

        return $depositRepository->exportReport();
    }

    public function resyncDeposit(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('superadmin'), 403);
        $deposit = Deposit::where('id', $request->get('id'))->first();

        if (!$deposit) {
            return response()->json(['success' => false]);
        }

        $deposit->wallet_transfer_status = DEPOSIT_PENDING;
        $deposit->updated_at = Carbon::now();
        $deposit->save();

        return response()->json([
            'success' => true,
            'wallet_transfer_status' => $deposit->wallet_transfer_status,
            'wallet_transfer_ago' => $deposit->wallet_transfer_ago,
        ]);
    }

    public function confirmPendingDeposit(Request $request)
    {
        if (!$this->currentUserIsSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only super administrators can confirm deposits',
            ], 403);
        }

        $deposit = Deposit::where('id', $request->get('id'))->first();

        if (!$deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit not found',
            ], 404);
        }

        if ($deposit->status === DEPOSIT_CONFIRMED) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit already confirmed',
            ], 422);
        }

        if ($deposit->status !== DEPOSIT_IGNORED) {
            return response()->json([
                'success' => false,
                'message' => 'Only ignored deposits can be changed to pending',
            ], 422);
        }

        $deposit->status = DEPOSIT_PENDING;
        $deposit->updated_at = Carbon::now();
        $deposit->save();

        return response()->json([
            'success' => true,
            'status' => $deposit->status,
        ]);
    }

    public function recheckDeposit(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('superadmin'), 403);
        $address = trim((string) $request->get('address'));

        if ($address === '') {
            return response()->json(['success' => false]);
        }

        $normalizedAddress = mb_strtolower($address);

        $wallets = WalletAddress::with(['wallet.currency', 'user'])
            ->where(function ($query) use ($address, $normalizedAddress) {
                $query->where('address', $address)
                    ->orWhere('address', $normalizedAddress)
                    ->orWhereRaw('LOWER(address) = ?', [$normalizedAddress]);
            })
            ->get();

        if ($wallets->isEmpty()) {
            return response()->json(['success' => false]);
        }

        $scanned = [];

        foreach ($wallets as $wallet) {
            foreach ($this->resolveDepositRecheckNetworks($wallet, $normalizedAddress) as $network) {
                $key = (int) $wallet->user_id . ':' . (int) $network . ':' . mb_strtolower((string) $wallet->address);

                if (isset($scanned[$key])) {
                    continue;
                }

                $scanned[$key] = [
                    'user_id' => (int) $wallet->user_id,
                    'network_id' => (int) $network,
                    'address' => $wallet->address,
                ];

                $this->runDepositRecheckForNetwork($wallet, (int) $network);
            }
        }

        return response()->json([
            'success' => true,
            'wallet_count' => $wallets->count(),
            'scan_count' => count($scanned),
            'scanned' => array_values($scanned),
        ]);
    }

    protected function resolveDepositRecheckNetworks($wallet, string $normalizedAddress): array
    {
        $networks = [(int) $wallet->network_id];

        /*
         * 0x 地址是 EVM 地址，同一个地址可能同时用于 ETH/ERC、BNB/BEP、Polygon。
         * 手动补扫时不能只扫 wallet_addresses 查到的第一条链。
         */
        if (str_starts_with($normalizedAddress, '0x')) {
            $networks = array_merge($networks, [
                NETWORK_BNB,
                NETWORK_BEP,
                NETWORK_ETH,
                NETWORK_ERC,
                NETWORK_MATIC,
                NETWORK_MATIC20,
                NETWORK_XLAYER,
                NETWORK_XLAYER20,
                NETWORK_CUSTOMTOKEN_NETWORK,
                NETWORK_CUSTOMTOKEN_TOKEN,
            ]);
        }

        /*
         * Tron 地址同理兼容 TRX 主币和 TRC20 代币。
         */
        if (str_starts_with($normalizedAddress, 't')) {
            $networks = array_merge($networks, [
                NETWORK_TRX,
                NETWORK_TRC,
            ]);
        }

        return array_values(array_unique(array_filter(array_map('intval', $networks))));
    }

    protected function runDepositRecheckForNetwork($wallet, int $network): void
    {
        try {
            if ($network == NETWORK_BNB) {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol('BNB');
                (new MonitorBnbDepositsCommand())->check($wallet, $currency);
                return;
            }

            if ($network == NETWORK_BEP) {
                (new MonitorBepDepositsCommand())->check($wallet);
                return;
            }

            if ($network == NETWORK_ETH) {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol('ETH');
                (new MonitorEthereumDepositsCommand())->check($wallet, $currency);
                return;
            }

            if ($network == NETWORK_ERC) {
                (new MonitorErcDepositsCommand())->check($wallet);
                return;
            }

            if ($network == NETWORK_MATIC) {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol('POL');
                (new MonitorMaticDepositsCommand())->check($wallet, $currency);
                return;
            }

            if ($network == NETWORK_MATIC20) {
                (new MonitorMatic20DepositsCommand())->check($wallet);
                return;
            }

            if (in_array($network,[NETWORK_XLAYER,NETWORK_XLAYER20],true)) {
                \Illuminate\Support\Facades\Artisan::call('deepro:scan-evm-deposits',['chain'=>'xlayer']);
                return;
            }

            if ($network == NETWORK_TRX) {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol('TRX');
                (new MonitorTrxDepositsCommand())->check($wallet, $currency);
                return;
            }

            if ($network == NETWORK_TRC) {
                (new MonitorTrcDepositsCommand())->check($wallet);
                return;
            }

            if ($network == NETWORK_CUSTOMTOKEN_TOKEN) {
                (new MonitorTokenDepositsCommand())->check($wallet);
                return;
            }

            if ($network == NETWORK_CUSTOMTOKEN_NETWORK) {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol('CUSTOMTOKEN');
                (new HandleDeposit())->check($wallet, $currency);
            }
        } catch (\Throwable $e) {
            Log::warning('Deposit recheck failed', [
                'address' => $wallet->address ?? null,
                'user_id' => $wallet->user_id ?? null,
                'network_id' => $network,
                'message' => $e->getMessage(),
            ]);
        }
    }

public function fiatDeposits()
{
    $depositRepository = new FiatDepositRepository();

    $deposits = $depositRepository->getReport();

    $deposits->getCollection()->transform(function ($deposit) {
        $this->attachAdminDepositDisplayTimes($deposit);

        return $deposit;
    });

    return Inertia::render('Admin/Reports/FiatDeposits', [
        'filters' => request()->all([
            'search',
            'type',
            'status',
            'payment_method',
            'user_id',
            'deposit_id',
            'currency',
            'bank',
            'period',
            'per_page',
            'referrer',
        ]),
        'deposits' => $deposits,
    ]);
}


    public function forceLiquidate(Request $request)
    {
        $request->validate([
            'uuid' => ['required'],
        ]);

        $result = (new OrderRepository())->forceLiquidateFutures($request->get('uuid'));
        $status = (int) ($result['status'] ?? (($result['success'] ?? false) ? 200 : 500));

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? 'Force liquidation failed',
        ], $status);
    }

public function withdrawals()
{
    $perPage = \App\Support\AdminReportFilters::perPage();

    request()->merge([
        'per_page' => $perPage,
    ]);

    $withdrawalRepository = new WithdrawalRepository();

    $withdrawals = $withdrawalRepository->getReport();

    $leaderCache = [];
    $userCache = [];
    $referrerChainUserCache = [];

    $withdrawals->getCollection()->transform(function ($withdrawal) use (&$leaderCache, &$userCache, &$referrerChainUserCache) {
        $leaderDisplayName = '';
        $referrerDisplayName = '';
        $referrerChain = [];

        /*
         * 单条提现金额 / 手续费折算成 USDT。
         * 使用 markets.last 作为该币种价格。
         */
        $currencyId = (int) ($withdrawal->currency_id ?? optional($withdrawal->currency)->id ?? 0);

        $amountUsdt = $this->convertWithdrawalAmountToUsdt(
            $withdrawal->amount ?? 0,
            $currencyId
        );

        $feeUsdt = $this->convertWithdrawalAmountToUsdt(
            $withdrawal->fee ?? 0,
            $currencyId
        );

        $withdrawal->amount_usdt = math_formatter($amountUsdt, 8, '.', '');
        $withdrawal->amount_usdt_display = $this->formatWithdrawalUsdtAmount($amountUsdt);

        $withdrawal->fee_usdt = math_formatter($feeUsdt, 8, '.', '');
        $withdrawal->fee_usdt_display = $this->formatWithdrawalUsdtAmount($feeUsdt);

        if ($withdrawal->user) {
            $leaderDisplayName = $this->resolveLeaderDisplayNameForWithdrawalUser(
                $withdrawal->user,
                $leaderCache,
                $userCache
            );

            $referrerDisplayName = $this->resolveReferrerDisplayNameForWithdrawalUser(
                $withdrawal->user,
                $userCache
            );

            $referrerChain = $this->buildDepositUserReferrerChain(
                $withdrawal->user,
                $referrerChainUserCache
            );

            $withdrawal->user->leader_display_name = $leaderDisplayName;
            $withdrawal->user->referrer_display_name = $referrerDisplayName;
            $withdrawal->user->referrer_chain = $referrerChain;
        }

        $withdrawal->leader_display_name = $leaderDisplayName;
        $withdrawal->referrer_display_name = $referrerDisplayName;
        $withdrawal->referrer_chain = $referrerChain;

        return $withdrawal;
    });

    $networks = Network::query()
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

    return Inertia::render('Admin/Reports/Withdrawals', [
        'filters' => request()->all([
            'search',
            'type',
            'status',
            'network_id',
            'user_id',
            'txn',
            'address',
            'payment_id',
            'period',
            'referrer',
            'team_user_id',
            'per_page',
        ]),
        'withdrawals' => $withdrawals,
        'networks' => $networks,
    ]);
}
protected function convertWithdrawalAmountToUsdt($amount, int $currencyId): string
{
    if (!is_numeric($amount) || (float) $amount <= 0 || $currencyId <= 0) {
        return '0';
    }

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        return '0';
    }

    $symbol = strtoupper(trim((string) $currency->symbol));

    if ($symbol === 'USDT' || $symbol === 'USD') {
        return math_formatter($amount, 8, '.', '');
    }

    $rate = $this->getWithdrawalCurrencyToUsdtRateByCurrencyId($currencyId);

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

protected function getWithdrawalCurrencyToUsdtRateByCurrencyId(int $currencyId): string
{
    static $rateCache = [];

    if ($currencyId <= 0) {
        return '0';
    }

    if (isset($rateCache[$currencyId])) {
        return $rateCache[$currencyId];
    }

    $currency = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency || empty($currency->symbol)) {
        $rateCache[$currencyId] = '0';
        return '0';
    }

    $symbol = strtoupper(trim((string) $currency->symbol));

    if ($symbol === 'USDT' || $symbol === 'USD') {
        $rateCache[$currencyId] = '1';
        return '1';
    }

    $usdt = \Illuminate\Support\Facades\DB::table('currencies')
        ->select(['id', 'symbol'])
        ->whereRaw('UPPER(symbol) = ?', ['USDT'])
        ->first();

    $usd = \Illuminate\Support\Facades\DB::table('currencies')
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
     * markets.last 就是这个代币价格。
     */
    $directQuery = \Illuminate\Support\Facades\DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->where('base_currency_id', $currencyId)
        ->whereIn('quote_currency_id', $quoteIds)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $directQuery->orderByRaw('CASE WHEN quote_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END');
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
    $reverseQuery = \Illuminate\Support\Facades\DB::table('markets')
        ->select(['id', 'base_currency_id', 'quote_currency_id', 'last'])
        ->whereIn('base_currency_id', $quoteIds)
        ->where('quote_currency_id', $currencyId)
        ->whereNotNull('last')
        ->where('last', '>', 0);

    if ($usdt) {
        $reverseQuery->orderByRaw('CASE WHEN base_currency_id = ' . (int) $usdt->id . ' THEN 0 ELSE 1 END');
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

protected function formatWithdrawalUsdtAmount($value): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    $value = str_replace(',', '', (string) $value);

    if (!is_numeric($value)) {
        return '0';
    }

    $formatted = math_formatter($value, 2);

    if (strpos($formatted, '.') !== false) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted === '' ? '0' : $formatted;
}
protected function resolveReferrerDisplayNameForWithdrawalUser($user, array &$userCache): string
{
    if (!$user || empty($user->referral_id)) {
        return '-';
    }

    $referrerId = (int) $user->referral_id;

    if ($referrerId <= 0) {
        return '-';
    }

    if (!array_key_exists($referrerId, $userCache)) {
        $userCache[$referrerId] = \App\Models\User\User::query()
            ->select('id', 'email', 'nickname', 'referral_code')
            ->where('id', $referrerId)
            ->first();
    }

    $referrer = $userCache[$referrerId];

    if (!$referrer) {
        return '-';
    }

    if (!empty($referrer->nickname)) {
        return $referrer->nickname;
    }

    if (!empty($referrer->email)) {
        return $referrer->email;
    }

    if (!empty($referrer->referral_code)) {
        return $referrer->referral_code;
    }

    return 'ID: ' . $referrer->id;
}

    public function exportWithdrawals()
    {
        $withdrawalRepository = new WithdrawalRepository();

        return $withdrawalRepository->exportReport();
    }

public function fiatWithdrawals()
{
    $withdrawalRepository = new FiatWithdrawalRepository();

    $withdrawals = $withdrawalRepository->getReport();

    return Inertia::render('Admin/Reports/FiatWithdrawals', [
        'filters' => request()->all([
            'search',
            'type',
            'status',
            'payment_method',
            'user_id',
            'withdrawal_id',
            'currency',
            'bank',
            'period',
            'per_page',
            'referrer',
        ]),
        'withdrawals' => $withdrawals,
    ]);
}


    public function trades()
    {
        $perPage = (int) request('per_page', 10);

        if (!in_array($perPage, [10, 50, 100, 500], true)) {
            $perPage = 10;
        }

        request()->merge([
            'per_page' => $perPage,
        ]);

        $transactionRepository = new TransactionRepository();

        $transactions = $transactionRepository->getReport();

        $markets = \App\Models\Market\Market::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Reports/Trades', [
            'filters' => request()->all([
                'search',
                'market_id',
                'side',
                'order_type',
                'user_id',
                'email',
                'price_min',
                'price_max',
                'fee_min',
                'fee_max',
                'base_amount_min',
                'base_amount_max',
                'quote_amount_min',
                'quote_amount_max',
                'period',
                'referrer',
                'per_page',
            ]),
            'transactions' => $transactions,
            'markets' => $markets,
        ]);
    }

    public function tradesExport()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID',
                'Market',
                'Side',
                'Order Type',
                'Executed Price',
                'Fee',
                'Amount (Base)',
                'Amount (Quote)',
                'User Email',
                'User ID',
                'Date',
            ]);

            $query = (new TransactionRepository())->getReportQuery();

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    $marketName = optional($t->market)->name ?? '';
                    $baseSymbol = optional(optional($t->market)->baseCurrency)->symbol ?? '';
                    $quoteSymbol = optional(optional($t->market)->quoteCurrency)->symbol ?? '';

                    fputcsv($out, [
                        $t->id,
                        $marketName,
                        $t->order_side,
                        $t->order_type,
                        $t->price,
                        $t->fee,
                        $t->base_currency . ' ' . $baseSymbol,
                        $t->quote_currency . ' ' . $quoteSymbol,
                        optional($t->user)->email,
                        optional($t->user)->id,
                        $t->created_at,
                    ]);
                }
            });

            fclose($out);
        }, 'admin_trades_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    protected function attachAdminDepositDisplayTimes($deposit): void
    {
        if (!$deposit) {
            return;
        }

        $deposit->created_at_display = $this->formatFuturesReportDateTime(
            $deposit->getRawOriginal('created_at') ?: $deposit->created_at
        );
        $deposit->updated_at_display = $this->formatFuturesReportDateTime(
            $deposit->getRawOriginal('updated_at') ?: $deposit->updated_at
        );
    }

public function futures()
{
    return $this->renderFuturesReport('active');
}

public function futuresActive()
{
    return $this->renderFuturesReport('active');
}

public function futuresHistory()
{
    return $this->renderFuturesReport('history');
}

protected function renderFuturesReport(string $pageStatus)
{
    $pageStatus = $pageStatus === 'history' ? 'history' : 'active';
    $requestedStatus = request()->get('status');

    if ($pageStatus === 'active') {
        request()->merge([
            'status' => 'active',
        ]);
    } else {
        request()->merge([
            'status' => in_array($requestedStatus, ['closed', 'liquidated'], true)
                ? $requestedStatus
                : 'history',
        ]);
    }

    $transactionRepository = new FuturesTransactionRepository();

    $transactions = $transactionRepository->getReport();

    /*
     * 给合约记录补充：
     * 推荐人、组别、所有上级链。
     * 这里复用充值记录页面的推荐人 / 组别逻辑。
     */
    $leaderCache = [];
    $userCache = [];
    $referrerChainUserCache = [];

    $this->warmDepositUserReferrerCacheForUsers(
        $transactions->getCollection()->pluck('user')->filter()->values()->all(),
        $referrerChainUserCache
    );

    $transactions->getCollection()->transform(function ($transaction) use (&$leaderCache, &$userCache, &$referrerChainUserCache) {
        $leaderDisplayName = '';
        $referrerDisplayName = '';
        $referrerChain = [];

        if ($transaction->user) {
            $leaderDisplayName = $this->resolveLeaderDisplayNameForDepositUser(
                $transaction->user,
                $leaderCache,
                $userCache
            );

            $referrerDisplayName = $this->resolveReferrerDisplayNameForDepositUser(
                $transaction->user,
                $userCache
            );

            /*
             * 获取当前用户的所有上级推荐人。
             * 顺序：直属上级 -> 上上级 -> 更上级。
             */
            $referrerChain = $this->buildDepositUserReferrerChain(
                $transaction->user,
                $referrerChainUserCache
            );

            $transaction->user->leader_display_name = $leaderDisplayName;
            $transaction->user->referrer_display_name = $referrerDisplayName;
            $transaction->user->referrer_chain = $referrerChain;
        }

        $transaction->leader_display_name = $leaderDisplayName;
        $transaction->referrer_display_name = $referrerDisplayName;
        $transaction->referrer_chain = $referrerChain;
        $transaction->opened_at = $this->formatFuturesReportDateTime(
            $transaction->getRawOriginal('created_at') ?: $transaction->created_at
        );
        $transaction->closed_at = $transaction->status === 'active'
            ? null
            : $this->formatFuturesReportDateTime(
                $transaction->getRawOriginal('updated_at') ?: $transaction->updated_at
            );
        $this->attachLiveFuturesPnl($transaction);

        return $transaction;
    });

    $markets = \App\Models\Market\Market::query()
        ->orderBy('name')
        ->pluck('name')
        ->values();

    /*
     * 当前开仓统计
     * 固定统计 status = active 的当前开仓。
     *
     * 这里已经排除虚拟账户：
     * users.is_xn = true 的用户不参与统计。
     * users.is_xn = false 或 NULL 的用户参与统计。
     */
	    $statsQuery = \App\Models\Order\FuturesContract::query()
	        ->has('market')
	        ->has('user')
	        ->with(['market', 'user'])
	        ->where('status', 'active')
        ->whereHas('user', function ($userQuery) {
            $userQuery->where(function ($q) {
	                $q->where('is_xn', false)
	                    ->orWhereNull('is_xn');
	            });
	        });

	    app(\App\Services\Admin\AdminGroupFilterService::class)
	        ->applyToQuery($statsQuery, 'user_id');

	    $currentUser = auth()->user();

	    if (!$currentUser) {
	        $statsQuery->whereRaw('1 = 0');
	    } else {
	        $currentUser->loadMissing('roles');

	        $roleNames = $currentUser->roles
	            ->pluck('name')
	            ->filter()
	            ->values()
	            ->toArray();

	        $roleIds = $currentUser->roles
	            ->pluck('id')
	            ->map(function ($id) {
	                return (int) $id;
	            })
	            ->toArray();

	        $isSuperAdmin = in_array('superadmin', $roleNames, true) || (auth()->user()?->hasRole('superadmin') ?? false);

	        if (!$isSuperAdmin) {
	            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

	            if (empty($teamUserIds)) {
	                $statsQuery->whereRaw('1 = 0');
	            } else {
	                $statsQuery->whereIn('user_id', $teamUserIds);
	            }
	        }
	    }
	
	    if (request()->filled('search')) {
        $search = trim(request()->get('search'));

        $statsQuery->where(function ($query) use ($search) {
            $query->where('id', 'like', '%' . $search . '%')
                ->orWhereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('referral_code', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');

                    if (is_numeric($search)) {
                        $userQuery->orWhere('id', (int) $search);
                    }
                })
                ->orWhereHas('market', function ($marketQuery) use ($search) {
                    $marketQuery->where('name', 'like', '%' . $search . '%');
                });
        });
    }

    if (request()->filled('user_id')) {
        $statsQuery->where('user_id', (int) request()->get('user_id'));
    }

    if (request()->filled('market')) {
        $market = request()->get('market');

        $statsQuery->whereHas('market', function ($query) use ($market) {
            $query->where('name', $market);
        });
    }

    if (request()->filled('type')) {
        $statsQuery->where('type', request()->get('type'));
    }

    if (request()->filled('side')) {
        $side = request()->get('side');

        if ($side === 'long') {
            $statsQuery->where('is_long', true);
        }

        if ($side === 'short') {
            $statsQuery->where('is_long', false);
        }
    }

    if (request()->filled('min_balance')) {
        $statsQuery->where('balance', '>=', request()->get('min_balance'));
    }

    if (request()->filled('max_balance')) {
        $statsQuery->where('balance', '<=', request()->get('max_balance'));
    }

    if (request()->filled('min_leverage')) {
        $statsQuery->where('leverage', '>=', request()->get('min_leverage'));
    }

    if (request()->filled('max_leverage')) {
        $statsQuery->where('leverage', '<=', request()->get('max_leverage'));
    }

    $period = request()->get('period', []);

    if (is_array($period) && !empty($period[0]) && !empty($period[1])) {
        $start = strlen($period[0]) <= 10 ? $period[0] . ' 00:00:01' : $period[0];
        $end = strlen($period[1]) <= 10 ? $period[1] . ' 23:59:59' : $period[1];

        $statsQuery->whereBetween('created_at', [$start, $end]);
    }

    /*
     * 一次聚合同时得到开仓数、开仓人数和仓位金额，避免重复执行三遍相同筛选。
     */
    $statsRow = (clone $statsQuery)
        ->selectRaw('COUNT(*) AS active_count')
        ->selectRaw('COUNT(DISTINCT user_id) AS active_user_count')
        ->selectRaw('COALESCE(SUM(balance), 0) AS active_position_amount_total')
        ->toBase()
        ->first();

    $activeCount = (int) ($statsRow->active_count ?? 0);
    $activeUserCount = (int) ($statsRow->active_user_count ?? 0);
    $activePositionAmountTotal = $statsRow->active_position_amount_total ?? 0;

    $stats = [
        'active_user_count' => $activeUserCount,
        'active_count' => $activeCount,
        'active_position_amount_total' => math_formatter($activePositionAmountTotal ?: 0, 8),
    ];

    return Inertia::render('Admin/Reports/Futures', [
        'filters' => request()->all([
            'search',
            'type',
            'referrer',
            'period',
            'market',
            'status',
            'side',
            'user_id',
            'min_balance',
            'max_balance',
            'min_leverage',
            'max_leverage',
            'per_page',
        ]),
        'transactions' => $transactions,
        'markets' => $markets,
        'stats' => $stats,
        'currentStatus' => $pageStatus,
    ]);
}

protected function formatFuturesReportDateTime($value): ?string
{
    if (!$value) {
        return null;
    }

    try {
        if ($value instanceof Carbon) {
            $value = $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value, $matches)) {
            return str_replace('T', ' ', $matches[0]);
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        return (string) $value;
    }
}
public function futuresLivePrices()
{
    $markets = request()->input('markets', []);
    $ids = request()->input('ids', []);

    if (is_string($markets)) {
        $markets = array_filter(explode(',', $markets));
    }

    if (is_string($ids)) {
        $ids = array_filter(explode(',', $ids));
    }

    if (!is_array($markets)) {
        $markets = [];
    }

    if (!is_array($ids)) {
        $ids = [];
    }

    $markets = array_values(array_unique(array_filter($markets)));
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

    if (empty($markets)) {
        return response()->json([
            'prices' => [],
            'positions' => [],
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    $marketRows = \App\Models\Market\Market::query()
        ->whereIn('name', $markets)
        ->get(['id', 'name', 'last']);

    $prices = [];

    foreach ($marketRows as $market) {
        $last = null;

        try {
            $last = function_exists('market_get_stats')
                ? market_get_stats($market->id, 'last')
                : null;
        } catch (\Throwable $e) {
            $last = null;
        }

        if (!is_numeric($last) || (float) $last <= 0) {
            $last = $market->last ?? 0;
        }

        $prices[$market->name] = (float) $last;
    }

    $positions = [];

    if (!empty($ids)) {
        $contracts = FuturesContract::query()
            ->with(['market.quoteCurrency', 'market.baseCurrency'])
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->get();

        foreach ($contracts as $contract) {
            $positions[(string) $contract->id] = $this->buildLiveFuturesPnlPayload($contract, $prices);
        }
    }

    return response()->json([
        'prices' => $prices,
        'positions' => $positions,
        'server_time' => now()->toDateTimeString(),
    ]);
}

protected function attachLiveFuturesPnl($contract): void
{
    if (!$contract || $contract->status !== 'active') {
        return;
    }

    $payload = $this->buildLiveFuturesPnlPayload($contract);

    if (empty($payload)) {
        return;
    }

    $contract->market_price = $payload['market_price'];
    $contract->pnl = $payload['pnl'];
    $contract->pnlAmount = $payload['pnlAmount'];
    $contract->pnl_profitable = $payload['pnl_profitable'];
}

protected function buildLiveFuturesPnlPayload($contract, array $prices = []): array
{
    if (!$contract || !$contract->market) {
        return [];
    }

    $market = $contract->market;
    $marketPrice = isset($prices[$market->name])
        ? (float) $prices[$market->name]
        : 0;

    if ($marketPrice <= 0) {
        try {
            $marketPrice = function_exists('market_get_stats')
                ? (float) market_get_stats($market->id, 'last')
                : 0;
        } catch (\Throwable $e) {
            $marketPrice = 0;
        }
    }

    if ($marketPrice <= 0) {
        $marketPrice = (float) ($market->last ?? 0);
    }

    $precision = (int) ($market->quote_precision ?? 8);

    if ($marketPrice <= 0) {
        return [
            'market_price' => 0,
            'pnl' => (float) ($contract->pnl ?? 0),
            'pnlAmount' => 0,
            'pnl_profitable' => ((float) ($contract->pnl ?? 0)) >= 0,
        ];
    }

    $pnl = futures_pnl_calculate(
        $contract->quantity,
        $contract->price,
        math_formatter($marketPrice, $precision, false, true),
        $contract->leverage,
        $contract->is_long
    );

    $pnlAmount = math_formatter(
        math_percentage($contract->balance, $pnl),
        $precision,
        false,
        true
    );

    return [
        'market_price' => (float) $marketPrice,
        'pnl' => (float) $pnl,
        'pnlAmount' => (float) $pnlAmount,
        'pnl_profitable' => ((float) $pnl) >= 0,
    ];
}
    public function fundingFeeDistributions()
    {
        $repository = new FundingFeeDistributionRepository();
        $distributions = $repository->getReport();

        return Inertia::render('Admin/Reports/FundingFeeDistributions', [
            'filters' => request()->all(['user_id', 'market', 'start_date', 'end_date', 'search']),
            'distributions' => $distributions,
        ]);
    }

    public function futuresExport()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID',
                'Market',
                'Position',
                'Entry Price',
                'Liquidation Price',
                'PNL %',
                'Position Size',
                'Leverage',
                'Released Amount',
                'Status',
                'User Email',
                'User ID',
                'Date',
            ]);

            $query = FuturesContract::query();

            app(\App\Services\Admin\AdminGroupFilterService::class)
                ->applyToQuery($query, 'futures_contract.user_id');

            $query->filterUser(request()->only(['market', 'type']))->orderByLatest();
            $query->has('market')->has('user');
            $query->with(['market.quoteCurrency', 'market.baseCurrency', 'user']);
            $query->whereHas('user', function ($userQuery) {
                $userQuery->where(function ($q) {
                    $q->where('is_xn', false)
                        ->orWhereNull('is_xn');
                });
            });

            $status = request()->get('status');

            if (!$this->currentUserHasFullFinanceAccess()) {
                $status = 'active';
            }

            if ($status !== null && $status !== '') {

                if ($status === 'history') {
                    $query->whereIn('status', ['closed', 'liquidated']);
                } else {
                    $query->where('status', $status);
                }
            }

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
                $query->whereBetween('created_at', [$start, $end]);
            }

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    fputcsv($out, [
                        $t->id,
                        optional($t->market)->name,
                        $t->is_long ? 'Long' : 'Short',
                        $t->price,
                        $t->liquidation_price,
                        $t->pnl,
                        $t->balance,
                        $t->leverage,
                        $t->released_amount,
                        $t->status,
                        optional($t->user)->email,
                        optional($t->user)->id,
                        $t->created_at,
                    ]);
                }
            });

            fclose($out);
        }, 'admin_futures_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    protected function currentUserHasFullFinanceAccess(): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return false;
        }

        $currentUser->loadMissing('roles');

        $roleNames = $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();

        return in_array('superadmin', $roleNames, true) ||
            in_array('admin', $roleNames, true) ||
            in_array('finance_manager', $roleNames, true) ||
            in_array('user_leader', $roleNames, true) ||
            in_array('perm_finances', $roleNames, true);
    }

    protected function currentUserIsSalesman(): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return false;
        }

        $currentUser->loadMissing('roles');

        return !$this->currentUserHasFullFinanceAccess() && $currentUser->roles
            ->pluck('name')
            ->contains('salesman');
    }

    public function options()
    {
        $transactionRepository = new OptionRepository();

        $transactions = $transactionRepository->getReport();

        return Inertia::render('Admin/Reports/Options', [
            'filters' => request()->all(['search', 'type', 'referrer', 'period']),
            'transactions' => $transactions,
            'settlementReviews' => auth()->user()->hasRole('superadmin') ? DB::table('option_settlement_reviews')->whereIn('status', ['pending','refund_requested'])->orderBy('created_at')->limit(100)->get(['option_id','status','reason','expires_at','requested_by','created_at']) : [],
        ]);
    }

    public function optionsExport()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID',
                'Market',
                'Type',
                'Enter Price',
                'Closed Price',
                'Period',
                'Amount',
                'User Email',
                'User ID',
                'Status',
                'PNL',
                'Template Used',
                'Date',
            ]);

            $query = Option::query();

            app(\App\Services\Admin\AdminGroupFilterService::class)
                ->applyToQuery($query, 'options.user_id');

            $query->filter(request()->only(['search', 'referrer']))->orderByLatest();
            $query->has('market')->has('user')->has('currency');
            $query->with(['market', 'currency', 'user']);

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
                $query->whereBetween('created_at', [$start, $end]);
            }

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    fputcsv($out, [
                        $t->id,
                        optional($t->market)->name,
                        $t->type,
                        $t->price,
                        $t->market_price,
                        $t->period,
                        $t->amount . ' ' . optional($t->currency)->symbol,
                        optional($t->user)->email,
                        optional($t->user)->id,
                        $t->status,
                        $t->status === 'active'
                            ? 'N/A'
                            : ($t->status === 'won' ? '+' . $t->pnl : '-' . $t->amount) . ' ' . optional($t->currency)->symbol,
                        $t->is_exception ? 'Used (ID: ' . $t->template_id . ')' : 'Not Used',
                        $t->created_at,
                    ]);
                }
            });

            fclose($out);
        }, 'admin_options_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function wallets()
    {
        $walletRepository = new WalletRepository();

        $wallets = $walletRepository->getReport();

        $search = request()->get('search');
        $type = request()->get('type', 'all');
        $referrer = request()->get('referrer');
        $user = request()->get('user');

        return Inertia::render('Admin/Reports/Wallets', [
            'filters' => [
                'search' => $search,
                'type' => $type,
                'referrer' => $referrer,
                'user' => $user,
            ],
            'wallets' => $wallets,
        ]);
    }

public function transferWallets(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('superadmin'), 403);
        $request->validate(['account_type' => 'required|in:real,virtual']);
        if ($request->input('account_type') === 'real') {
            $proposal = app(\App\Services\Admin\FundTransferReview::class)->propose(auth()->user(), $request->all());
            return response()->json(['success' => true, 'pending_review' => $proposal->status === 'pending', 'proposal_id' => $proposal->id]);
        }
        $data = $request->validate([
            'wallet' => 'required|integer|exists:wallets,id', 'idempotency_key' => 'required|uuid',
            'balance_type' => 'required|in:wallet,trade', 'amount' => ['required','regex:/^-?\d{1,18}(?:\.\d{1,18})?$/D'],
            'note' => 'required|string|min:10|max:1000',
        ]);
        return DB::transaction(function () use ($data) {
            $wallet = \App\Models\Wallet\Wallet::whereKey($data['wallet'])->lockForUpdate()->firstOrFail();
            $actor = auth()->user();
            $prior = DB::table('admin_fund_transfers')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($prior) {
                abort_unless($prior->proposed_by == $actor->id && $prior->target_wallet_id == $wallet->id && $prior->funding_domain === 'virtual' && bccomp($prior->amount, $data['amount'], 18) === 0 && $prior->target_field === 'balance_in_virtual_' . $data['balance_type'], 409);
                return response()->json(['success' => true, 'proposal_id' => $prior->id]);
            }
            $owner = \App\Models\User\User::whereKey($wallet->user_id)->lockForUpdate()->firstOrFail();
            if (!$owner->is_xn && !$owner->is_xm) throw \Illuminate\Validation\ValidationException::withMessages(['wallet' => __('Simulation credits require a simulation account.')]);
            $field = 'balance_in_virtual_' . $data['balance_type'];
            $before = (string) ($wallet->{$field} ?? '0');
            $after = bcadd($before, $data['amount'], 18);
            if (bccomp($after, '0', 18) < 0 || bccomp($data['amount'], '0', 18) === 0) throw \Illuminate\Validation\ValidationException::withMessages(['amount' => __('Insufficient balance')]);
            $wallet->{$field} = $after; $wallet->save();
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('admin_fund_transfers')->insert([
                'id' => $id, 'idempotency_key' => $data['idempotency_key'], 'funding_domain' => 'virtual',
                'proposed_by' => $actor->id, 'reviewed_by' => $actor->id, 'source_wallet_id' => $wallet->id,
                'target_wallet_id' => $wallet->id, 'currency_id' => $wallet->currency_id, 'source_field' => 'simulation_issuance',
                'target_field' => $field, 'amount' => $data['amount'], 'status' => 'completed', 'reference' => 'simulation',
                'reason' => $data['note'], 'ledger' => json_encode(['domain' => 'virtual', 'before' => $before, 'after' => $after]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::afterCommit(fn () => event(new WalletUpdated($wallet)));
            return response()->json(['success' => true, 'proposal_id' => $id]);
        }, 3);
    }

    public function finances()
    {
        return Inertia::render('Admin/Reports/Finances', [
            'filters' => [
                'type' => request()->get('type', 'all'),
                'referrer' => request()->get('referrer'),
            ],
        ]);
    }

    public function lendingTransactions()
    {
        $transactionRepository = new LendingUserRepository();

        $transactions = $transactionRepository->get(true);

        return Inertia::render('Admin/Reports/LendingTransactions', [
            'filters' => request()->all(['search', 'referrer']),
            'transactions' => $transactions,
        ]);
    }

    public function financesFetch()
    {
        $type = request()->get('type', 'trades');

        $end = Carbon::now()->format('Y-m-d 23:59:59');
        $start = Carbon::now()->format('Y-m-d 00:00:01');

        $period = request()->get('period', []);

        $period[0] = isset($period[0]) ? $period[0] . ' 00:00:01' : $start;
        $period[1] = isset($period[1]) ? $period[1] . ' 23:59:59' : $end;

        $reports = [];

        if ($type == 'trades') {
            $reports = (new TransactionRepository())->getStatReport($period);
        }

        if ($type == 'deposits') {
            $reports = (new DepositRepository())->getStatReport($period);
        }

        if ($type == 'withdrawals') {
            $reports = (new WithdrawalRepository())->getStatReport($period);
        }

        if ($type == 'fiat_deposits') {
            $reports = (new FiatDepositRepository())->getStatReport($period);
        }

        if ($type == 'fiat_withdrawals') {
            $reports = (new FiatWithdrawalRepository())->getStatReport($period);
        }

        if ($type == 'peer_trades') {
            $reports = (new PeerOrderRepository())->getStatReport($period);
        }

        if ($type == 'options') {
            $reports = (new OptionRepository())->getStatReport($period);

            if (isset($reports[0]->income)) {
                $income = $reports[0]->income;
                $reports[0]->income = $income > 0 ? 0 - $income : $income;
            }
        }

        if ($type == 'futures') {
            $reports = (new FuturesTransactionRepository())->getStatReport($period);
        }

        return response()->json([
            $type => $reports,
        ]);
    }

    public function financesExport()
    {
        return response()->streamDownload(function () {
            $type = request()->get('type', 'trades');

            $endDefault = Carbon::now()->format('Y-m-d 23:59:59');
            $startDefault = Carbon::now()->format('Y-m-d 00:00:01');

            $period = request()->get('period', []);

            $start = isset($period[0]) && $period[0]
                ? (strlen($period[0]) <= 10 ? ($period[0] . ' 00:00:01') : $period[0])
                : $startDefault;

            $end = isset($period[1]) && $period[1]
                ? (strlen($period[1]) <= 10 ? ($period[1] . ' 23:59:59') : $period[1])
                : $endDefault;

            $reports = [];

            if ($type === 'trades') {
                $reports = (new TransactionRepository())->getStatReport([$start, $end]);
            } elseif ($type === 'deposits') {
                $reports = (new DepositRepository())->getStatReport([$start, $end]);
            } elseif ($type === 'withdrawals') {
                $reports = (new WithdrawalRepository())->getStatReport([$start, $end]);
            } elseif ($type === 'fiat_deposits') {
                $reports = (new FiatDepositRepository())->getStatReport([$start, $end]);
            } elseif ($type === 'fiat_withdrawals') {
                $reports = (new FiatWithdrawalRepository())->getStatReport([$start, $end]);
            } elseif ($type === 'peer_trades') {
                $reports = (new PeerOrderRepository())->getStatReport([$start, $end]);
            } elseif ($type === 'options') {
                $reports = (new OptionRepository())->getStatReport([$start, $end]);

                if (isset($reports[0]->income)) {
                    $income = $reports[0]->income;
                    $reports[0]->income = $income > 0 ? 0 - $income : $income;
                }
            } elseif ($type === 'futures') {
                $reports = (new FuturesTransactionRepository())->getStatReport([$start, $end]);
            }

            $out = fopen('php://output', 'w');

            switch ($type) {
                case 'trades':
                    fputcsv($out, [
                        'Market',
                        'Income From Trades',
                        'Referral Earnings',
                        'Number of Trades',
                        'Base Volume',
                        'Quote Volume',
                    ]);

                    foreach ($reports as $r) {
                        fputcsv($out, [
                            $r->name,
                            $r->income . ' ' . ($r->symbol ?? ''),
                            $r->referrals . ' ' . ($r->symbol ?? ''),
                            $r->total,
                            $r->basevolume . ' ' . ($r->basesymbol ?? ''),
                            $r->quotevolume . ' ' . ($r->symbol ?? ''),
                        ]);
                    }
                    break;

                case 'peer_trades':
                    fputcsv($out, [
                        'Pair',
                        'Maker Income',
                        'Taker Income',
                        'Total Income',
                        'Number of Trades',
                    ]);

                    foreach ($reports as $r) {
                        $totalIncome = (float) $r->maker_income + (float) $r->taker_income;

                        fputcsv($out, [
                            $r->name,
                            $r->maker_income . ' ' . ($r->symbol ?? ''),
                            $r->taker_income . ' ' . ($r->symbol ?? ''),
                            $totalIncome . ' ' . ($r->symbol ?? ''),
                            $r->total,
                        ]);
                    }
                    break;

                case 'deposits':
                case 'withdrawals':
                case 'fiat_deposits':
                case 'fiat_withdrawals':
                    fputcsv($out, [
                        'Currency',
                        ucfirst(explode('_', $type)[0]) . ' Amount',
                        'Income From ' . ucfirst(str_replace('_', ' ', $type)),
                        'Number of ' . ucfirst(str_replace('_', ' ', $type)),
                    ]);

                    foreach ($reports as $r) {
                        fputcsv($out, [
                            $r->name,
                            $r->volume . ' ' . $r->name,
                            $r->income . ' ' . $r->name,
                            $r->total,
                        ]);
                    }
                    break;

                case 'options':
                    fputcsv($out, [
                        'Pair',
                        'Income',
                        'Number of Bids',
                    ]);

                    foreach ($reports as $r) {
                        fputcsv($out, [
                            $r->pair,
                            $r->income . ' ' . ($r->symbol ?? ''),
                            $r->total,
                        ]);
                    }
                    break;

                case 'futures':
                    fputcsv($out, [
                        'Pair',
                        'Income',
                        'Number of Positions',
                    ]);

                    foreach ($reports as $r) {
                        fputcsv($out, [
                            $r->pair,
                            $r->income . ' ' . ($r->symbol ?? ''),
                            $r->total,
                        ]);
                    }
                    break;

                default:
                    fputcsv($out, ['No data']);
                    break;
            }

            fclose($out);
        }, 'admin_finances_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function systemWallets()
    {
        $currencyRepository = new CurrencyRepository();

        $requests = request()->only(['search', 'type']);

        $currencies = $currencyRepository->getReport($requests);

        $search = request()->get('search');
        $type = request()->get('type', 'all');
        $referrer = request()->get('referrer');

        return Inertia::render('Admin/Reports/SystemWallets', [
            'filters' => [
                'search' => $search,
                'type' => $type,
                'referrer' => $referrer,
            ],
            'currencies' => $currencies,
        ]);
    }

    public function referralTransactions()
    {
        $transactionRepository = new ReferralTransactionRepository();

        $transactions = $transactionRepository->getReport();

        return Inertia::render('Admin/Reports/ReferralTransactions', [
            'filters' => request()->all(['search', 'referrer']),
            'transactions' => $transactions,
        ]);
    }

    public function launchpadTransactions()
    {
        $transactionRepository = new LaunchpadRepository();

        $transactions = $transactionRepository->getReport();

        return Inertia::render('Admin/Reports/LaunchpadTransactions', [
            'filters' => request()->all(['search', 'referrer']),
            'transactions' => $transactions,
        ]);
    }

    public function stakingTransactions()
    {
        $transactionRepository = new StakingUserRepository();

        $transactions = $transactionRepository->get(true);

        return Inertia::render('Admin/Reports/StakingTransactions', [
            'filters' => request()->all(['search', 'referrer']),
            'transactions' => $transactions,
        ]);
    }

    public function transferCommissions()
    {
        $repository = new TransferCommissionRepository();
        $commissions = $repository->getReport();

        return Inertia::render('Admin/Reports/TransferCommissions', [
            'filters' => request()->all(['search', 'referrer', 'period']),
            'commissions' => $commissions,
        ]);
    }

    public function walletTransfers()
    {
        $repository = new WalletTransferRecordRepository();

        return Inertia::render('Admin/Reports/WalletTransfers', [
            'filters' => request()->all([
                'search',
                'referrer',
                'user_id',
                'referral',
                'currency',
                'direction',
                'period',
                'per_page',
            ]),
            'records' => $repository->getAdminReport(),
        ]);
    }

    public function walletTransfersExport()
    {
        $repository = new WalletTransferRecordRepository();
        $query = $repository->getAdminQuery(request()->only([
            'search',
            'referrer',
            'user_id',
            'referral',
            'currency',
            'direction',
            'period',
        ]));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'ID',
                '用户邮箱',
                '用户ID',
                '推荐码',
                '币种',
                '划转方向',
                '划转金额',
                '到账金额',
                '原手续费',
                '手续费返还',
                '实际手续费',
                '手续费率',
                '日期',
            ]);

            if ($query) {
                $query->orderBy('id')->chunkById(1000, function ($records) use ($out) {
                    foreach ($records as $record) {
                        $symbol = optional($record->currency)->symbol;

                        fputcsv($out, [
                            $record->id,
                            optional($record->user)->email,
                            $record->user_id,
                            optional($record->user)->referral_code,
                            $symbol,
                            $this->walletTransferDirectionLabel($record->direction),
                            $this->formatWalletTransferAmount($record->amount).' '.$symbol,
                            $this->formatWalletTransferAmount($record->credited_amount).' '.$symbol,
                            $this->formatWalletTransferAmount($record->original_fee_amount).' '.$symbol,
                            $this->formatWalletTransferAmount($record->fee_refund_amount).' '.$symbol,
                            $this->formatWalletTransferAmount($record->fee_amount).' '.$symbol,
                            $this->formatWalletTransferAmount($record->fee_percent).'%',
                            $record->created_at,
                        ]);
                    }
                });
            }

            fclose($out);
        }, 'admin_wallet_transfer_records_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    protected function walletTransferDirectionLabel(string $direction): string
    {
        return [
            'to_trade' => '资金账户 -> 交易账户',
            'to_funding' => '交易账户 -> 资金账户',
        ][$direction] ?? $direction;
    }

    protected function formatWalletTransferAmount($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    public function transferCommissionsExport()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID',
                'User Email',
                'Currency',
                'Direction',
                'Transfer Amount',
                'Percent (%)',
                'Commission Amount',
                'Credited Amount',
                'Date',
            ]);

            $query = \App\Models\Wallet\TransferCommission::query();
            $query->filter(request()->only(['search', 'referrer']))->orderByLatest();
            $query->has('currency')->has('user');
            $query->with(['currency', 'user']);

            app(\App\Services\Admin\AdminGroupFilterService::class)
                ->applyToQuery($query, 'user_id');

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
                $query->whereBetween('created_at', [$start, $end]);
            }

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [
                        $c->id,
                        optional($c->user)->email,
                        optional($c->currency)->symbol,
                        $c->direction,
                        $c->amount . ' ' . optional($c->currency)->symbol,
                        $c->percent,
                        $c->commission_amount . ' ' . optional($c->currency)->symbol,
                        $c->credited_amount . ' ' . optional($c->currency)->symbol,
                        $c->created_at,
                    ]);
                }
            });

            fclose($out);
        }, 'admin_transfer_commissions_export.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function moderateWithdrawal(Request $request, Withdrawal $withdrawal)
    {
        if (!$this->currentUserIsSuperAdmin()) {
            abort(403, 'Only super administrators can moderate withdrawals.');
        }

        if ($request->get('manual')) {
            (new WithdrawalRepository())->moderateManual($withdrawal, $request->get('txn'));

            return Redirect::route('admin.reports.withdrawals');
        }

        (new WithdrawalRepository())->moderate(
            $withdrawal,
            $request->get('action'),
            $request->get('txn')
        );

        return Redirect::route('admin.reports.withdrawals');
    }

    public function moderateFiatDeposit(Request $request, FiatDeposit $deposit)
    {
        abort_unless(auth()->user()?->hasRole('superadmin'), 403);
        (new FiatDepositRepository())->moderate($deposit, $request->get('action'));

        return Redirect::route('admin.reports.deposits.fiat');
    }

    public function moderateFiatWithdrawal(Request $request, FiatWithdrawal $withdrawal)
    {
        if (!$this->currentUserIsSuperAdmin()) {
            abort(403, 'Only super administrators can moderate withdrawals.');
        }

        (new FiatWithdrawalRepository())->moderate($withdrawal, $request->get('action'));

        return Redirect::route('admin.reports.withdrawals.fiat');
    }

    public function closeOption(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('superadmin'), 403);
        $data = $request->validate(['id'=>['required','integer','exists:options,id'],'review_action'=>['nullable','in:request_refund,approve_refund'],'note'=>['required_with:review_action','string','min:8','max:1000']]);
        $action=$data['review_action']??null;
        if ($action==='request_refund') app(\App\Services\Option\OptionSettlementReview::class)->requestRefund((int)$data['id'],(int)auth()->id(),$data['note']);
        elseif($action==='approve_refund') app(\App\Services\Option\OptionSettlementReview::class)->approveRefund((int)$data['id'],(int)auth()->id(),$data['note']);
        else app(\App\Services\Option\OptionFunds::class)->cancelScheduled((int)$data['id']);
        return response()->json(['success'=>true,'status'=>Option::findOrFail($data['id'])->status]);
    }
    protected function currentUserIsSuperAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole('superadmin');
    }
}
