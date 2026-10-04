<?php

namespace App\Repositories\Withdrawal;

use App\Events\WithdrawalUpdated;
use App\Interfaces\Withdrawal\WithdrawalRepositoryInterface;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Mail\Withdrawals\WithdrawalRejected;
use App\Models\ColdStorage\ColdStorage;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Models\Wallet\WalletAddress;
use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use App\Support\AdminReportFilters;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class WithdrawalRepository implements WithdrawalRepositoryInterface
{
    /**
     * @var Withdrawal
     */
    protected $withdrawal;

    /**
     * WithdrawalRepository constructor.
     */
    public function __construct()
    {
        $this->withdrawal = new Withdrawal();
    }

    public function get($type = 'coin')
    {
        $withdrawal = Withdrawal::query();

        $withdrawal->with('currency');

        $withdrawal->has('currency');

        if ($type == 'fiat') {
            $withdrawal->fiat();
        } else {
            $withdrawal->coin();
        }

        $withdrawal->orderBy('created_at', 'desc');

        $withdrawal->limit(10);

        return $withdrawal->get();
    }

    public function getReport()
    {
        $withdrawal = \App\Models\Withdrawal\Withdrawal::query();

        $withdrawal->has('currency')->has('user');
        $withdrawal->with(['currency', 'network', 'user']);
        $withdrawal->orderBy('updated_at', 'desc');

        $filters = request()->only([
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
        ]);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $withdrawal->where(function ($q) use ($search) {
                $q->where('withdrawals.withdrawal_id', 'like', "%{$search}%")
                    ->orWhere('withdrawals.txn', 'like', "%{$search}%")
                    ->orWhere('withdrawals.address', 'like', "%{$search}%")
                    ->orWhere('withdrawals.payment_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%");
                        if (ctype_digit($search)) $uq->orWhere('id', $search);
                    });
            });
        }

        if (!empty($filters['type'])) {
            $withdrawal->where('withdrawals.type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $withdrawal->where('withdrawals.status', $filters['status']);
        }

        if (!empty($filters['network_id'])) {
            $withdrawal->where('withdrawals.network_id', $filters['network_id']);
        }

        if (!empty($filters['user_id'])) {
            $withdrawal->where('withdrawals.user_id', $filters['user_id']);
        }

        if (!empty($filters['txn'])) {
            $withdrawal->where('withdrawals.txn', 'like', '%' . trim($filters['txn']) . '%');
        }

        if (!empty($filters['address'])) {
            $withdrawal->where('withdrawals.address', 'like', '%' . trim($filters['address']) . '%');
        }

        if (!empty($filters['payment_id'])) {
            $withdrawal->where('withdrawals.payment_id', 'like', '%' . trim($filters['payment_id']) . '%');
        }

        if ($period = AdminReportFilters::period()) {
            $this->applyCreatedOrUpdatedDateRange($withdrawal, $period[0], $period[1]);
        }

        $this->applyAdminTeamScope($withdrawal, 'withdrawals.user_id');

        return $withdrawal->paginate(AdminReportFilters::perPage())->withQueryString();
    }

    public function exportReport()
    {
        $withdrawal = \App\Models\Withdrawal\Withdrawal::query();

        $withdrawal->has('currency')->has('user');
        $withdrawal->with(['currency', 'network', 'user']);
        $withdrawal->orderBy('updated_at', 'desc');

        $filters = request()->only([
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
        ]);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $withdrawal->where(function ($q) use ($search) {
                $q->where('withdrawals.withdrawal_id', 'like', "%{$search}%")
                    ->orWhere('withdrawals.txn', 'like', "%{$search}%")
                    ->orWhere('withdrawals.address', 'like', "%{$search}%")
                    ->orWhere('withdrawals.payment_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%");
                        if (ctype_digit($search)) $uq->orWhere('id', $search);
                    });
            });
        }

        if (!empty($filters['type'])) {
            $withdrawal->where('withdrawals.type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $withdrawal->where('withdrawals.status', $filters['status']);
        }

        if (!empty($filters['network_id'])) {
            $withdrawal->where('withdrawals.network_id', $filters['network_id']);
        }

        if (!empty($filters['user_id'])) {
            $withdrawal->where('withdrawals.user_id', $filters['user_id']);
        }

        if (!empty($filters['txn'])) {
            $withdrawal->where('withdrawals.txn', 'like', '%' . trim($filters['txn']) . '%');
        }

        if (!empty($filters['address'])) {
            $withdrawal->where('withdrawals.address', 'like', '%' . trim($filters['address']) . '%');
        }

        if (!empty($filters['payment_id'])) {
            $withdrawal->where('withdrawals.payment_id', 'like', '%' . trim($filters['payment_id']) . '%');
        }

        if ($period = AdminReportFilters::period()) {
            $this->applyCreatedOrUpdatedDateRange($withdrawal, $period[0], $period[1]);
        }

        $this->applyAdminTeamScope($withdrawal, 'withdrawals.user_id');

        $list = $withdrawal->get();

        $fileName = 'withdrawals_' . date('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];

        $columns = [
            '创建时间',
            '通过时间',
            '状态',
            '提现ID',
            '交易哈希',
            '金额',
            '手续费',
            '币种',
            '地址',
            '备注',
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
                    $item->status,
                    $item->withdrawal_id,
                    $item->txn,
                    $item->amount,
                    $item->fee,
                    optional($item->currency)->symbol,
                    $item->address,
                    $item->payment_id,
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

    public function getTotalWithdrawalAmount($teamUserId = null, $startDate = null, $endDate = null)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return math_formatter(0, 2);
        }

        $hasDateRange = !empty($startDate) && !empty($endDate);

        $query = DB::table('withdrawals')
            ->join('users', 'withdrawals.user_id', '=', 'users.id')
            ->where('withdrawals.status', WITHDRAWAL_CONFIRMED_BY_PROVIDER)
            ->whereIn('withdrawals.currency_id', [2, 11])
            ->where(function ($q) {
                $q->where('withdrawals.source_id', '!=', 'virtual')
                    ->orWhereNull('withdrawals.source_id');
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

        if (!$teamUserId && request()->filled('team_user_id')) {
            $teamUserId = (int) request()->get('team_user_id');
        }

        if ($this->isSuperAdmin()) {
            if (!empty($teamUserId)) {
                $teamUserIds = $this->getAllTeamUserIds((int) $teamUserId);

                if (empty($teamUserIds)) {
                    return math_formatter(0, 2);
                }

                $amount = $query
                    ->whereIn('withdrawals.user_id', $teamUserIds)
                    ->sum('withdrawals.amount');

                return math_formatter($amount, 2);
            }

            $amount = $query->sum('withdrawals.amount');

            return math_formatter($amount, 2);
        }

        if ($this->hasTeamDataScope()) {
            $currentUser = auth()->user();

            $myTeamTreeUserIds = $this->getAllTeamTreeUserIds((int) $currentUser->id);
            $myTeamTreeUserIds = array_map('intval', $myTeamTreeUserIds);

            if (empty($myTeamTreeUserIds)) {
                return math_formatter(0, 2);
            }

            if (!empty($teamUserId)) {
                /*
                 * 权限判断用完整团队树，不过滤 is_xn。
                 * 这样不会因为组长或中间节点是虚拟账户而无法筛选。
                 */
                if (!in_array((int) $teamUserId, $myTeamTreeUserIds, true)) {
                    return math_formatter(0, 2);
                }

                $teamUserIds = $this->getAllTeamUserIds((int) $teamUserId);

                if (empty($teamUserIds)) {
                    return math_formatter(0, 2);
                }

                $amount = $query
                    ->whereIn('withdrawals.user_id', $teamUserIds)
                    ->sum('withdrawals.amount');

                return math_formatter($amount, 2);
            }

            $teamUserIds = $this->filterRealUserIds($myTeamTreeUserIds);

            if (empty($teamUserIds)) {
                return math_formatter(0, 2);
            }

            $amount = $query
                ->whereIn('withdrawals.user_id', $teamUserIds)
                ->sum('withdrawals.amount');

            return math_formatter($amount, 2);
        }

        return math_formatter(0, 2);
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
        $currentUser = auth()->user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isSuperAdmin()) {
            return $query;
        }

        if ($this->hasTeamDataScope()) {
            /*
             * 权限范围：
             * 先查完整团队树，不过滤上级是否虚拟账户。
             * 提现审核列表需要显示虚拟账户提现，所以这里不再过滤最终用户。
             */
            $teamTreeUserIds = $this->getAllTeamTreeUserIds((int) $currentUser->id);
            $teamUserIds = array_values(array_unique(array_map('intval', $teamTreeUserIds)));

            if (empty($teamUserIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($userColumn, $teamUserIds);
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
         * 只过滤最终要统计 / 显示的用户：
         * is_xn = true 不统计、不显示
         * is_xn = false / NULL 统计、显示
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

    public function getStatReport($period)
    {
        $query = DB::table('withdrawals')
            ->join('users', 'withdrawals.user_id', '=', 'users.id')
            ->join('currencies', 'currencies.id', 'withdrawals.currency_id')
            ->selectRaw('currencies.symbol as name, SUM(withdrawals.amount) as volume, SUM(withdrawals.fee) as income, COUNT(*) as total')
            ->where('withdrawals.status', WITHDRAWAL_CONFIRMED_BY_PROVIDER)
            ->where(function ($q) {
                $q->where('withdrawals.source_id', '!=', 'virtual')
                    ->orWhereNull('withdrawals.source_id');
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
                "((withdrawals.created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}')::date between ? and ?",
                [$startDate, $endDate]
            )->orWhereRaw(
                "((withdrawals.updated_at AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}')::date between ? and ?",
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

    public function getReportUser(User $user, $pagination = true)
    {
        $withdrawal = Withdrawal::query();

        $withdrawal
            ->filterUser(request()->only(['currency', 'txn', 'status']))
            ->with(['currency.file'])
            ->orderByLatest();

        if ($this->canAccessUser((int) $user->id)) {
            $withdrawal->where('withdrawals.user_id', $user->id);
        } else {
            $withdrawal->whereRaw('1 = 0');
        }

        if (!$pagination) {
            return $withdrawal->limit(15)->get();
        }

        return $withdrawal->paginate(50)->withQueryString();
    }

    public function count()
    {
        $withdrawal = Withdrawal::query();

        return $withdrawal->count();
    }

    public function countCoin()
    {
        $withdrawal = Withdrawal::query();
        $withdrawal->coin();

        return $withdrawal->count();
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

    public function getWithdrawal($id)
    {
        return Withdrawal::with('currency')->whereId($id)->first();
    }

    public function store($data)
    {
        return $this->withdrawal->create($data);
    }

    public function update($withdrawal, $data)
    {
        return $withdrawal->update($data);
    }

    public function getBySource($source_id, $network)
    {
        return Withdrawal::with('currency')->where('source_id', $source_id)->where('network_id', $network)->first();
    }

    /**
     * Moderate Withdrawal
     */
    public function moderate($withdrawal, $action, $txn = false)
    {
        return DB::transaction(function () use ($withdrawal, $action, $txn) {
            $withdrawal = Withdrawal::whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();
            if ($withdrawal->status != WITHDRAWAL_WAITING_APPROVAL) {
                return false;
            }

            $wallet = (new WalletRepository())->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency_id);
            $walletService = new WalletService();

            if ($action == "approve") {
                if ($withdrawal->type === 'coin') app(\App\Services\Wallet\WithdrawalNetworkPolicy::class)->assertSupported((int)$withdrawal->currency_id,(int)$withdrawal->network_id,(bool)$withdrawal->internal_id);
                if ($this->isVirtualWithdrawalUser($withdrawal)) {
                    return $this->approveVirtualWithdrawal($withdrawal, $wallet);
                }

                $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_SYSTEM;
                $withdrawal->save();

                $response = $walletService->withdrawCryptoConfirmed($withdrawal->fresh(), $txn);

                if ($response['status'] == STATUS_VALIDATION_ERROR) {
                    $withdrawal->status = WITHDRAWAL_FAILED;
                    $withdrawal->rejected_reason = $response['message'];
                    $withdrawal->update();

                    if ($withdrawal->fund_origin !== 'treasury' && $withdrawal->source_id !== 'system') {
                        $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                        $walletService->increase($wallet, $withdrawal->amount, 'wallet');
                    } else {
                        DB::table('cold_storage')
                            ->where('cold_storage_transaction_id', $withdrawal->id)
                            ->update(['cold_storage_transaction_id' => null]);
                    }
                } else {
                    $withdrawal->fresh();

                    $withdrawal->source_id = $response['source'];
                    $withdrawal->initial_raw = json_encode($response['message']);

                    if (isset($response['txn'])) {
                        $withdrawal->txn = $response['txn'];
                    }

                    if (str_starts_with((string)$response['source'], 'custody:')) {
                        $withdrawal->status = WITHDRAWAL_WAITING_PROVIDER_APPROVAL;
                    } elseif ($withdrawal->network_id == NETWORK_BTC || $withdrawal->network_id == NETWORK_BRC20) {
                        $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                    } elseif ($response['source'] == "internal") {
                        $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;

                        $walletRepository = new WalletRepository();

                        $internalUser = WalletAddress::where('address', $withdrawal->address)->first();

                        $depositWallet = $walletRepository->getWalletByCurrency($internalUser->user_id, $withdrawal->currency_id, false);

                        $walletRepository->depositInternal($depositWallet, $withdrawal->amount);
                    } else {
                        $withdrawal->status = WITHDRAWAL_WAITING_PROVIDER_APPROVAL;

                        if (isset($response['txn'])) {
                            $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                        }
                    }

                    $withdrawal->update();
                }
            } else {
                if ($this->isVirtualWithdrawalUser($withdrawal)) {
                    return $this->rejectVirtualWithdrawal($withdrawal, $wallet);
                }

                $withdrawal->status = WITHDRAWAL_REJECTED;
                $withdrawal->rejected_reason = nl2br((string) request()->get('reason', ''));
                $withdrawal->save();

                if ($withdrawal->fund_origin !== 'treasury' && $withdrawal->source_id !== 'system') {
                    $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                    $walletService->increase($wallet, $withdrawal->amount, 'wallet');

                    Mail::to($withdrawal->user)->queue(new WithdrawalRejected($withdrawal->user, $withdrawal->amount, $withdrawal->currency->symbol, $withdrawal->rejected_reason));
                } else {
                    DB::table('cold_storage')
                        ->where('cold_storage_transaction_id', $withdrawal->id)
                        ->update(['cold_storage_transaction_id' => null]);
                }
            }

            return true;
        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    public function moderateManual($withdrawal, $txn)
    {
        return $this->completeVerified($withdrawal->id, (string) $txn, true);
    }

    public function completeVerified(int $id, string $txn, bool $manual = false): bool
    {
        $snapshot = Withdrawal::with(['currency', 'network', 'user'])->findOrFail($id);
        $expectedStatus = $manual ? WITHDRAWAL_WAITING_APPROVAL : WITHDRAWAL_WAITING_PROVIDER_APPROVAL;
        if ($snapshot->status !== $expectedStatus) return false;
        if ($this->isVirtualWithdrawalUser($snapshot)) {
            return DB::transaction(function () use ($id) {
                $row = Withdrawal::whereKey($id)->lockForUpdate()->firstOrFail();
                return $this->approveVirtualWithdrawal($row, (new WalletRepository())->getWalletByCurrency($row->user_id, $row->currency_id));
            }, DB_REPEAT_AFTER_DEADLOCK);
        }
        $proof = app(\App\Services\Withdrawal\VerifiedChainReceipt::class)->verify($snapshot, $txn);
        return DB::transaction(function () use ($id, $snapshot, $proof, $expectedStatus, $manual) {
            $row = Withdrawal::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->status !== $expectedStatus) return false;
            foreach (['currency_id','network_id','address','amount','fee','user_id','source_id'] as $field) {
                abort_unless((string) $row->{$field} === (string) $snapshot->{$field}, 409);
            }
            // A legacy completed withdrawal without a receipt journal must not be reused.
            $peerNetworks = match ((int)$proof['chain_id']) {56 => [NETWORK_BNB, NETWORK_BEP], 1 => [NETWORK_ETH, NETWORK_ERC], 137 => [NETWORK_MATIC, NETWORK_MATIC20], 196 => [NETWORK_XLAYER, NETWORK_XLAYER20], default => throw new \RuntimeException('Unsupported verified chain')};
            if (Withdrawal::where('id', '!=', $row->id)->whereIn('network_id', $peerNetworks)->whereRaw('LOWER(txn) = ?', [$proof['txn']])->exists()
                || DB::table('admin_chain_receipts')->where('claim_key', $proof['claim_key'])->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['txn' => __('This chain receipt has already been assigned.')]);
            }
            if ($row->fund_origin !== 'treasury' && $row->source_id !== 'system') {
                $wallet = \App\Models\Wallet\Wallet::where('user_id', $row->user_id)->where('currency_id', $row->currency_id)->orderBy('id')->lockForUpdate()->firstOrFail();
                if (bccomp((string) $wallet->balance_in_withdraw, (string) $row->amount, 18) < 0) throw \Illuminate\Validation\ValidationException::withMessages(['wallet' => __('Locked withdrawal balance is insufficient.')]);
                (new WalletService())->decrease($wallet, $row->amount, 'withdraw');
            }
            DB::table('admin_chain_receipts')->insert(['claim_key' => $proof['claim_key'], 'withdrawal_id' => $row->id, 'verified_by' => auth()->id() ?? 0, 'receipt' => json_encode($proof), 'created_at' => now()]);
            $row->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
            if ($manual) $row->source_id = 'manual_verified';
            $row->txn = $proof['txn']; $row->confirms = $proof['confirmations'];
            $row->initial_raw = json_encode(['verified_receipt' => $proof]);
            $row->save();
            if ($row->fund_origin === 'treasury') DB::table('cold_storage')->where('cold_storage_transaction_id',$row->id)->update(['cold_storage_transaction_id'=>null]);
            DB::afterCommit(function () use ($row) {
                event(new WithdrawalUpdated($row));
                if ($row->fund_origin !== 'treasury' && $row->source_id !== 'system') {
                    try { Mail::to($row->user)->queue(new WithdrawalConfirmed($row->user, $row->amount, $row->currency->symbol, $row->txn)); }
                    catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Withdrawal receipt verified; notification retry required', ['withdrawal_id' => $row->id]); }
                }
            });
            return true;
        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    protected function isVirtualWithdrawalUser(Withdrawal $withdrawal): bool
    {
        $withdrawal->loadMissing('user');

        $user = $withdrawal->user;

        if (!$user) {
            return false;
        }

        return filter_var($user->is_xn ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($user->is_xm ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function approveVirtualWithdrawal(Withdrawal $withdrawal, $wallet): bool
    {
        if ($withdrawal->status != WITHDRAWAL_WAITING_APPROVAL) {
            return false;
        }

        $this->decreaseLockedWithdrawalBalance($wallet, $withdrawal->amount);

        $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
        $withdrawal->source_id = 'virtual';
        $withdrawal->initial_raw = json_encode([
            'source' => 'virtual_account',
            'message' => 'Virtual account withdrawal approved without external transfer.',
        ]);
        $withdrawal->save();

        try {
            Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed(
                $withdrawal->user,
                $withdrawal->amount,
                $withdrawal->currency->symbol,
                __('Virtual account withdrawal approved')
            ));
        } catch (\Exception $e) {
        }

        event(new WithdrawalUpdated($withdrawal));

        return true;
    }

    protected function rejectVirtualWithdrawal(Withdrawal $withdrawal, $wallet): bool
    {
        if ($withdrawal->status != WITHDRAWAL_WAITING_APPROVAL) {
            return false;
        }

        $withdrawal->status = WITHDRAWAL_REJECTED;
        $withdrawal->rejected_reason = nl2br((string) request()->get('reason', ''));
        $withdrawal->save();

        $this->releaseLockedWithdrawalBalance($wallet, $withdrawal->amount);

        try {
            Mail::to($withdrawal->user)->queue(new WithdrawalRejected(
                $withdrawal->user,
                $withdrawal->amount,
                $withdrawal->currency->symbol,
                $withdrawal->rejected_reason
            ));
        } catch (\Exception $e) {
        }

        event(new WithdrawalUpdated($withdrawal));

        return true;
    }

    protected function decreaseLockedWithdrawalBalance($wallet, $amount): void
    {
        $amount = $this->normalizeDecimal($amount);

        if (!$wallet || math_compare($amount, 0) <= 0) {
            return;
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            return;
        }

        $columns = [
            'balance_in_virtual_withdraw',
            'balance_in_withdraw',
        ];

        foreach ($columns as $column) {
            if (!$this->walletColumnExists($column)) {
                continue;
            }

            $available = $this->normalizeDecimal($freshWallet->{$column} ?? 0);

            if (math_compare($available, $amount) >= 0) {
                $this->decreaseWalletColumn($freshWallet, $column, $amount);
                return;
            }
        }

        $remaining = $amount;

        foreach ($columns as $column) {
            if (!$this->walletColumnExists($column)) {
                continue;
            }

            $freshWallet->refresh();
            $available = $this->normalizeDecimal($freshWallet->{$column} ?? 0);

            if (math_compare($available, 0) <= 0) {
                continue;
            }

            $deduct = math_compare($available, $remaining) >= 0 ? $remaining : $available;

            $this->decreaseWalletColumn($freshWallet, $column, $deduct);

            $remaining = math_sub($remaining, $deduct);

            if (math_compare($remaining, 0) <= 0) {
                return;
            }
        }
    }

    protected function releaseLockedWithdrawalBalance($wallet, $amount): void
    {
        $amount = $this->normalizeDecimal($amount);

        if (!$wallet || math_compare($amount, 0) <= 0) {
            return;
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            return;
        }

        $virtualWalletColumn = $this->getVirtualWithdrawalReturnColumn();

        $pairs = [
            ['withdraw' => 'balance_in_virtual_withdraw', 'wallet' => $virtualWalletColumn],
            ['withdraw' => 'balance_in_withdraw', 'wallet' => $virtualWalletColumn ?: 'balance_in_wallet'],
        ];

        foreach ($pairs as $pair) {
            if (
                !$this->walletColumnExists($pair['withdraw']) ||
                !$pair['wallet'] ||
                !$this->walletColumnExists($pair['wallet'])
            ) {
                continue;
            }

            $available = $this->normalizeDecimal($freshWallet->{$pair['withdraw']} ?? 0);

            if (math_compare($available, $amount) >= 0) {
                $this->moveWalletColumn($freshWallet, $pair['withdraw'], $pair['wallet'], $amount);

                return;
            }
        }

        $remaining = $amount;

        foreach ($pairs as $pair) {
            if (
                !$this->walletColumnExists($pair['withdraw']) ||
                !$pair['wallet'] ||
                !$this->walletColumnExists($pair['wallet'])
            ) {
                continue;
            }

            $freshWallet->refresh();
            $available = $this->normalizeDecimal($freshWallet->{$pair['withdraw']} ?? 0);

            if (math_compare($available, 0) <= 0) {
                continue;
            }

            $deduct = math_compare($available, $remaining) >= 0 ? $remaining : $available;

            $this->moveWalletColumn($freshWallet, $pair['withdraw'], $pair['wallet'], $deduct);

            $remaining = math_sub($remaining, $deduct);

            if (math_compare($remaining, 0) <= 0) {
                return;
            }
        }
    }

    protected function getVirtualWithdrawalReturnColumn(): ?string
    {
        foreach (['balance_in_virtual_wallet', 'balance_in_virtual_trade', 'balance_in_wallet'] as $column) {
            if ($this->walletColumnExists($column)) {
                return $column;
            }
        }

        return null;
    }

    protected function decreaseWalletColumn(Wallet $wallet, string $column, $amount): void
    {
        $amount = $this->normalizeDecimal($amount);

        if (math_compare($amount, 0) <= 0 || !$this->walletColumnExists($column)) {
            return;
        }

        DB::statement(
            "UPDATE wallets
             SET {$column} = GREATEST(COALESCE({$column}, 0) - ?, 0),
                 updated_at = ?
             WHERE id = ?",
            [$amount, now(), $wallet->id]
        );
    }

    protected function moveWalletColumn(Wallet $wallet, string $fromColumn, string $toColumn, $amount): void
    {
        $amount = $this->normalizeDecimal($amount);

        if (
            math_compare($amount, 0) <= 0 ||
            !$this->walletColumnExists($fromColumn) ||
            !$this->walletColumnExists($toColumn)
        ) {
            return;
        }

        DB::statement(
            "UPDATE wallets
             SET {$fromColumn} = GREATEST(COALESCE({$fromColumn}, 0) - ?, 0),
                 {$toColumn} = COALESCE({$toColumn}, 0) + ?,
                 updated_at = ?
             WHERE id = ?",
            [$amount, $amount, now(), $wallet->id]
        );
    }

    protected function walletColumnExists(string $column): bool
    {
        try {
            return Schema::hasColumn('wallets', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function normalizeDecimal($value): string
    {
        $value = str_replace(',', '', (string) ($value ?? 0));

        return is_numeric($value) ? $value : '0';
    }
}
