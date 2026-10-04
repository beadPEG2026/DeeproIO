<?php

namespace App\Repositories\Deposit;

use App\Interfaces\Deposit\DepositRepositoryInterface;
use App\Mail\Deposits\DepositReceived;
use App\Mail\Deposits\DepositRejected;
use App\Models\Deposit\FiatDeposit;
use App\Models\User\User;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class FiatDepositRepository implements DepositRepositoryInterface
{
    /**
     * @var FiatDeposit
     */
    protected $deposit;

    /**
     * FiatDepositRepository constructor.
     */
    public function __construct()
    {
        $this->deposit = new FiatDeposit();
    }

    public function get($type = null)
    {
        $deposit = FiatDeposit::query();

        $deposit->with('currency');

        if ($type == 'bank') {
            $deposit->bank();
        } elseif ($type == 'cc') {
            $deposit->cc();
        }

        $deposit->orderBy('created_at', 'desc');

        $deposit->limit(10);

        return $deposit->get();
    }

    public function getReport($type = null)
    {
        $deposit = FiatDeposit::query();

        $deposit->with(['currency', 'user', 'receipt']);

        if ($type == 'bank') {
            $deposit->bank();
        } elseif ($type == 'cc') {
            $deposit->cc();
        }

        $this->applyAdminFilters($deposit, request()->only([
            'search',
            'type',
            'status',
            'payment_method',
            'user_id',
            'deposit_id',
            'currency',
            'bank',
            'period',
            'referrer',
        ]));

        $this->applyAdminTeamScope($deposit, 'fiat_deposits.user_id');

        $deposit->orderByLatest();

        return $this->paginateAndAppendAdminData($deposit);
    }

    protected function applyAdminFilters($deposit, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $keyword = mb_strtolower($search);
            $like = '%' . $keyword . '%';

            $deposit->where(function ($query) use ($like, $search) {
                $query->whereRaw('LOWER(COALESCE(fiat_deposits.deposit_id, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(fiat_deposits.type, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(fiat_deposits.status, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(fiat_deposits.note, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('CAST(fiat_deposits.amount AS TEXT) LIKE ?', ['%' . $search . '%'])
                    ->orWhereRaw('CAST(fiat_deposits.fee AS TEXT) LIKE ?', ['%' . $search . '%']);

                if ($this->columnExists('fiat_deposits', 'payment_method')) {
                    $query->orWhereRaw('LOWER(COALESCE(fiat_deposits.payment_method, \'\')) LIKE ?', [$like]);
                }

                if ($this->columnExists('fiat_deposits', 'bank_account_snapshot')) {
                    $query->orWhereRaw('LOWER(COALESCE(fiat_deposits.bank_account_snapshot, \'\')) LIKE ?', [$like]);
                }

                if ($this->columnExists('fiat_deposits', 'bank_account_id') && is_numeric($search)) {
                    $query->orWhere('fiat_deposits.bank_account_id', (int) $search);
                }

                if (is_numeric($search)) {
                    $query->orWhere('fiat_deposits.user_id', (int) $search);
                }

                $query->orWhereHas('user', function ($userQuery) use ($like, $search) {
                    $userQuery->whereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', [$like]);

                    if (is_numeric($search)) {
                        $userQuery->orWhere('id', (int) $search);
                    }
                });

                $query->orWhereHas('currency', function ($currencyQuery) use ($like) {
                    $currencyQuery->whereRaw('LOWER(COALESCE(symbol, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', [$like]);
                });
            });
        }

        if (!empty($filters['type'])) {
            $deposit->where('fiat_deposits.type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $deposit->where('fiat_deposits.status', $this->normalizeStatusValue($filters['status']));
        }

        if (!empty($filters['payment_method'])) {
            $paymentMethod = (string) $filters['payment_method'];

            $deposit->where(function ($query) use ($paymentMethod) {
                if ($this->columnExists('fiat_deposits', 'payment_method')) {
                    $query->where('fiat_deposits.payment_method', $paymentMethod);
                }

                $query->orWhere('fiat_deposits.type', $paymentMethod)
                    ->orWhere('fiat_deposits.note', 'like', '%"payment":"' . $paymentMethod . '"%')
                    ->orWhere('fiat_deposits.note', 'like', '%"payment_method":"' . $paymentMethod . '"%');
            });
        }

        if (!empty($filters['user_id'])) {
            $deposit->where('fiat_deposits.user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['referrer'])) {
            $deposit->where('fiat_deposits.user_id', (int) $filters['referrer']);
        }

        if (!empty($filters['deposit_id'])) {
            $depositId = mb_strtolower(trim((string) $filters['deposit_id']));
            $deposit->whereRaw('LOWER(COALESCE(fiat_deposits.deposit_id, \'\')) LIKE ?', ['%' . $depositId . '%']);
        }

        if (!empty($filters['currency'])) {
            $currency = mb_strtolower(trim((string) $filters['currency']));

            $deposit->where(function ($query) use ($currency) {
                $query->whereHas('currency', function ($currencyQuery) use ($currency) {
                    $currencyQuery->whereRaw('LOWER(COALESCE(symbol, \'\')) LIKE ?', ['%' . $currency . '%'])
                        ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', ['%' . $currency . '%']);
                })->orWhereRaw('LOWER(COALESCE(fiat_deposits.note, \'\')) LIKE ?', ['%"crypto_symbol":"%' . $currency . '%']);
            });
        }

        if (!empty($filters['bank'])) {
            $bank = mb_strtolower(trim((string) $filters['bank']));

            $deposit->where(function ($query) use ($bank) {
                $query->whereRaw("LOWER(COALESCE(fiat_deposits.note, '')) LIKE ?", ['%' . $bank . '%']);

                if ($this->columnExists('fiat_deposits', 'bank_account_snapshot')) {
                    $query->orWhereRaw("LOWER(COALESCE(fiat_deposits.bank_account_snapshot, '')) LIKE ?", ['%' . $bank . '%']);
                }

                if ($this->columnExists('fiat_deposits', 'bank_account_id') && is_numeric($bank)) {
                    $query->orWhere('fiat_deposits.bank_account_id', (int) $bank);
                }

                if ($this->tableExists('bank_accounts') && $this->columnExists('fiat_deposits', 'bank_account_id')) {
                    $query->orWhereExists(function ($bankQuery) use ($bank) {
                        $bankQuery->selectRaw('1')
                            ->from('bank_accounts')
                            ->whereColumn('bank_accounts.id', 'fiat_deposits.bank_account_id')
                            ->where(function ($bankWhere) use ($bank) {
                                foreach ([
                                    'iban',
                                    'account_number',
                                    'card_number',
                                    'card_no',
                                    'bic',
                                    'swift',
                                    'swift_code',
                                    'bank_name',
                                    'bank',
                                    'account_holder_name',
                                    'account_name',
                                    'first_name',
                                    'last_name',
                                    'name',
                                ] as $column) {
                                    if ($this->columnExists('bank_accounts', $column)) {
                                        $bankWhere->orWhereRaw("LOWER(COALESCE(bank_accounts." . $column . ", '')) LIKE ?", ['%' . $bank . '%']);
                                    }
                                }
                            });
                    });
                }
            });
        }

        $period = $this->parsePeriod($filters['period'] ?? null);

        if ($period) {
            [$start, $end] = $period;
            $deposit->whereBetween('fiat_deposits.created_at', [$start, $end]);
        }
    }

    protected function applyAdminTeamScope($query, string $userColumn)
    {
        app(\App\Services\Admin\AdminGroupFilterService::class)
            ->applyToQuery($query, $userColumn);

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

    protected function paginateAndAppendAdminData($deposit)
    {
        $deposits = $deposit->paginate($this->getPerPage())->withQueryString();

        $deposits->getCollection()->transform(function ($item) {
            return $this->appendAdminDisplayData($item);
        });

        return $deposits;
    }

    protected function appendAdminDisplayData($item)
    {
        $note = $this->decodeJsonValue($item->note);
        $snapshot = $this->decodeJsonValue($item->bank_account_snapshot ?? null);

        if (empty($snapshot) && !empty($note['bank_account_snapshot'])) {
            $snapshot = $this->decodeJsonValue($note['bank_account_snapshot']);
        }

        $bankAccountId = $item->bank_account_id
            ?? ($note['bank_account_id'] ?? null);

        $bankAccount = $this->getBankAccountFromTable($bankAccountId, (int) $item->user_id);

        if (!empty($bankAccount)) {
            $snapshot = array_merge($snapshot, $bankAccount);
        }

        $paymentMethod = $item->payment_method
            ?? ($note['payment_method'] ?? null)
            ?? ($note['payment'] ?? null)
            ?? ($item->type ?? null);

        $bankName = $this->pickValue($snapshot, [
            'bank_name',
            'bank',
            'bank_code',
            'institution_name',
            'branch_name',
        ]);

        $bankNumber = $this->pickValue($snapshot, [
            'iban',
            'account_number',
            'card_number',
            'card_no',
            'number',
        ]);

        $bic = $this->pickValue($snapshot, [
            'bic',
            'swift',
            'swift_code',
            'bank_swift',
        ]);

        $holderName = trim((string) ($this->pickValue($snapshot, [
            'account_holder_name',
            'holder_name',
            'account_name',
            'name',
            'real_name',
        ]) ?? ''));

        if ($holderName === '') {
            $firstName = (string) ($snapshot['first_name'] ?? '');
            $lastName = (string) ($snapshot['last_name'] ?? '');
            $holderName = trim($firstName . ' ' . $lastName);
        }

        $displayParts = [];

        if ($holderName !== '') {
            $displayParts[] = $holderName;
        }

        if ($bankName) {
            $displayParts[] = $bankName;
        }

        if ($bankNumber) {
            $displayParts[] = $bankNumber;
        }

        if ($bic) {
            $displayParts[] = $bic;
        }

        $item->admin_note_payload = $note;
        $item->admin_bank_snapshot = $snapshot;
        $item->admin_bank_info = [
            'bank_account_id' => $bankAccountId,
            'payment_method' => $paymentMethod,
            'display_name' => !empty($displayParts) ? implode(' - ', array_unique(array_filter($displayParts))) : null,
            'holder_name' => $holderName ?: null,
            'bank_name' => $bankName ?: null,
            'account_number' => $bankNumber ?: null,
            'bic' => $bic ?: null,
            'source' => !empty($bankAccount) ? 'bank_accounts' : 'deposit_note',
        ];

        $item->admin_deposit_detail = [
            'order_id' => $note['order_id'] ?? $item->deposit_id,
            'source' => $note['source'] ?? null,
            'crypto_currency_id' => $note['crypto_currency_id'] ?? null,
            'crypto_symbol' => $note['crypto_symbol'] ?? ($note['crypto'] ?? null),
            'crypto_amount' => $note['estimated_crypto_amount']
                ?? ($note['crypto_amount'] ?? null)
                ?? ($note['amountOut'] ?? null),
            'fiat_currency_id' => $note['fiat_currency_id'] ?? $item->currency_id,
            'fiat_symbol' => $note['fiat_symbol'] ?? optional($item->currency)->symbol,
            'fiat_amount' => $note['fiat_amount'] ?? $item->amount,
            'fee_percent' => $note['fee_percent'] ?? null,
            'fee_amount' => $note['fee_amount'] ?? $item->fee,
            'exchange_rate' => $note['exchange_rate'] ?? null,
            'price_in_fiat' => $note['price_in_fiat'] ?? null,
            'usdt_to_fiat_rate' => $note['usdt_to_fiat_rate'] ?? null,
        ];

        return $item;
    }

    protected function getBankAccountFromTable($bankAccountId, int $userId): array
    {
        if (empty($bankAccountId) || !$this->tableExists('bank_accounts')) {
            return [];
        }

        try {
            $query = DB::table('bank_accounts')->where('id', $bankAccountId);

            $userColumn = $this->findBankAccountUserColumn();

            if ($userColumn) {
                $query->where($userColumn, $userId);
            }

            $account = $query->first();

            if (!$account) {
                $account = DB::table('bank_accounts')->where('id', $bankAccountId)->first();
            }

            return $account ? (array) $account : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function findBankAccountUserColumn(): ?string
    {
        if ($this->columnExists('bank_accounts', 'user_id')) {
            return 'user_id';
        }

        if ($this->columnExists('bank_accounts', 'uid')) {
            return 'uid';
        }

        if ($this->columnExists('bank_accounts', 'client_id')) {
            return 'client_id';
        }

        return null;
    }

    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function getPerPage(): int
    {
        $perPage = (int) request()->get('per_page', 10);

        if ($perPage <= 0) {
            return 10;
        }

        return min($perPage, 200);
    }

    protected function parsePeriod($period): ?array
    {
        if (empty($period)) {
            return null;
        }

        try {
            if (is_array($period)) {
                $start = $period['start'] ?? $period[0] ?? null;
                $end = $period['end'] ?? $period[1] ?? null;

                if ($start && $end) {
                    return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()];
                }
            }

            $period = trim((string) $period);

            if ($period === '') {
                return null;
            }

            $parts = preg_split('/\s+to\s+|\s+-\s+|,|\|/i', $period);
            $parts = array_values(array_filter(array_map('trim', $parts)));

            if (count($parts) >= 2) {
                return [Carbon::parse($parts[0])->startOfDay(), Carbon::parse($parts[1])->endOfDay()];
            }

            $date = Carbon::parse($period);

            return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function normalizeStatusValue($status)
    {
        $status = (string) $status;

        $map = [
            'pending' => defined('FIAT_DEPOSIT_PENDING') ? FIAT_DEPOSIT_PENDING : 'pending',
            'confirmed' => defined('FIAT_DEPOSIT_CONFIRMED') ? FIAT_DEPOSIT_CONFIRMED : 'confirmed',
            'rejected' => defined('FIAT_DEPOSIT_REJECTED') ? FIAT_DEPOSIT_REJECTED : 'rejected',
        ];

        return $map[$status] ?? $status;
    }

    protected function decodeJsonValue($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function pickValue(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    protected function columnExists(string $table, string $column): bool
    {
        try {
            return DB::getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function maskBankNumber($value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return strlen($value) > 8
            ? substr($value, 0, 4) . ' **** ' . substr($value, -4)
            : '**** ' . substr($value, -4);
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
        $query = DB::table('fiat_deposits')
            ->selectRaw('currencies.symbol as name, SUM(fiat_deposits.amount) as volume, SUM(fiat_deposits.fee) as income, COUNT(*) as total')
            ->join('currencies', 'currencies.id', 'fiat_deposits.currency_id')
            ->where('fiat_deposits.status', FIAT_DEPOSIT_CONFIRMED)
            ->whereBetween('fiat_deposits.created_at', $period);

        app(\App\Services\Admin\AdminGroupFilterService::class)
            ->applyToQuery($query, 'fiat_deposits.user_id');

        return $query->groupByRaw('currencies.symbol')->get();
    }

    public function getReportUser(User $user, $type = 'all', $pagination = true)
    {
        $deposit = FiatDeposit::query();

        $deposit->filterUser(request()->only(['currency', 'status']))->orderByLatest();

        $deposit->with(['currency']);

        if ($type == 'cc') {
            $deposit->cc();
        } elseif ($type == 'bank') {
            $deposit->bank();
        }

        if ($this->canAccessUser((int) $user->id)) {
            $deposit->where('user_id', $user->id);
        } else {
            $deposit->whereRaw('1 = 0');
        }

        if (!$pagination) {
            $deposit->limit(15);

            return $deposit->get();
        }

        return $deposit->paginate(50)->withQueryString();
    }

    public function count()
    {
        $deposit = FiatDeposit::query();

        return $deposit->count();
    }

    public function getDeposit($id)
    {
        return FiatDeposit::with('currency')->whereId($id)->first();
    }

    public function store($data)
    {
        return $this->deposit->create($data);
    }

    public function update($deposit, $data)
    {
        return $deposit->update($data);
    }

    /**
     * Moderate Fiat Deposit
     */
    public function moderate($deposit, $action)
    {
        DB::transaction(function () use ($deposit, $action) {
            $deposit = FiatDeposit::query()
                ->with(['currency', 'user'])
                ->where('id', $deposit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($action == 'approve') {
                if ($this->isDepositConfirmed($deposit)) {
                    return;
                }

                $this->approveDepositAndCreditWallet($deposit);

                return;
            }

            if ($this->isDepositConfirmed($deposit)) {
                throw new \RuntimeException('Confirmed deposit cannot be rejected.');
            }

            $deposit->status = FIAT_DEPOSIT_REJECTED;
            $deposit->rejected_at = Carbon::now();
            $deposit->rejected_reason = nl2br(request()->get('reason'));
            $deposit->save();

            Mail::to($deposit->user)->queue(new DepositRejected(
                $deposit->user,
                math_formatter($deposit->amount, $deposit->currency->decimals),
                $deposit->currency->symbol,
                $deposit->rejected_reason
            ));
        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    protected function approveDepositAndCreditWallet(FiatDeposit $deposit): void
    {
        $walletRepository = new WalletRepository();
        $depositCreditService = new DepositCreditService();
        $currencyService = new CurrencyService();

        $note = $this->decodeJsonValue($deposit->note);

        if ($this->isLocalOnRampDeposit($deposit, $note)) {
            $creditCurrencyId = (int) ($note['crypto_currency_id'] ?? 0);
            $creditAmount = $this->normalizeAmount(
                $note['estimated_crypto_amount']
                    ?? ($note['crypto_amount'] ?? null)
                    ?? ($note['amountOut'] ?? null)
            );

            if ($creditCurrencyId <= 0 || $creditAmount <= 0) {
                throw new \RuntimeException('Invalid local onramp crypto credit data.');
            }

            $creditCurrency = (new CurrencyRepository())->get($creditCurrencyId);

            if (!$creditCurrency) {
                throw new \RuntimeException('Credit crypto currency not found.');
            }

            $deposit->status = FIAT_DEPOSIT_CONFIRMED;
            $deposit->approved_at = Carbon::now();

            $note['approved_credit_type'] = 'crypto_onramp';
            $note['approved_credit_currency_id'] = $creditCurrency->id;
            $note['approved_credit_symbol'] = $creditCurrency->symbol;
            $note['approved_credit_amount'] = math_formatter($creditAmount, $creditCurrency->decimals, '.', '');
            $note['approved_at'] = Carbon::now()->toDateTimeString();

            $deposit->note = json_encode($note, JSON_UNESCAPED_UNICODE);
            $deposit->save();

            $currencyService->increase($creditCurrency, $creditAmount);
            $creditCurrency->wallet_balance_updated_at = Carbon::now();
            $creditCurrency->update();

            $wallet = $walletRepository->getWalletByCurrency($deposit->user_id, $creditCurrency->id);
            $creditedAmount = $depositCreditService->credit($wallet, $creditAmount);

            Mail::to($deposit->user)->queue(new DepositReceived(
                $deposit->user,
                math_formatter($creditedAmount, $creditCurrency->decimals),
                $creditCurrency->symbol
            ));

            return;
        }

        $deposit->status = FIAT_DEPOSIT_CONFIRMED;
        $deposit->approved_at = Carbon::now();
        $deposit->save();

        $currencyService->increase($deposit->currency, $deposit->amount);
        $deposit->currency->wallet_balance_updated_at = Carbon::now();
        $deposit->currency->update();

        $fee = $deposit->fee;
        $amount = math_sub($deposit->amount, $fee);

        $wallet = $walletRepository->getWalletByCurrency($deposit->user_id, $deposit->currency_id);
        $creditedAmount = $depositCreditService->credit($wallet, $amount);

        Mail::to($deposit->user)->queue(new DepositReceived(
            $deposit->user,
            math_formatter($creditedAmount, $deposit->currency->decimals),
            $deposit->currency->symbol
        ));
    }

    protected function isLocalOnRampDeposit(FiatDeposit $deposit, array $note): bool
    {
        if (($note['source'] ?? null) === 'local_onramp') {
            return true;
        }

        if (!empty($note['crypto_currency_id']) && (
            isset($note['estimated_crypto_amount']) ||
            isset($note['crypto_amount']) ||
            isset($note['amountOut'])
        )) {
            return true;
        }

        return false;
    }

    protected function isDepositConfirmed(FiatDeposit $deposit): bool
    {
        $status = (string) $deposit->status;

        if (defined('FIAT_DEPOSIT_CONFIRMED') && $deposit->status == FIAT_DEPOSIT_CONFIRMED) {
            return true;
        }

        return $status === 'confirmed' || $status === '1';
    }

    protected function normalizeAmount($value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (float) str_replace(',', '', (string) $value);
    }
}
