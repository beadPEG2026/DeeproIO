<?php

namespace App\Repositories\Withdrawal;

use App\Interfaces\Withdrawal\WithdrawalRepositoryInterface;
use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Mail\Withdrawals\WithdrawalRejected;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Models\Withdrawal\FiatWithdrawal;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\PaymentGateways\Fiat\Payeer\Services\Payeer;
use App\Services\PaymentGateways\Fiat\PerfectMoney\Services\PerfectMoney;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Setting;

class FiatWithdrawalRepository implements WithdrawalRepositoryInterface
{
    /**
     * @var FiatWithdrawal
     */
    protected $withdrawal;

    /**
     * FiatWithdrawalRepository constructor.
     */
    public function __construct()
    {
        $this->withdrawal = new FiatWithdrawal();
    }

    public function get()
    {
        $withdrawal = FiatWithdrawal::query();

        $withdrawal->with('currency');

        $withdrawal->has('currency');

        $withdrawal->orderBy('created_at', 'desc');

        $withdrawal->limit(10);

        return $withdrawal->get();
    }

    public function getReport()
    {
        $withdrawal = FiatWithdrawal::query();

        $withdrawal->has('currency');

        $withdrawal->with(['currency', 'user', 'country']);

        $this->applyAdminFilters($withdrawal, request()->only([
            'search',
            'type',
            'status',
            'payment_method',
            'user_id',
            'withdrawal_id',
            'currency',
            'bank',
            'period',
            'referrer',
        ]));

        $this->applyAdminTeamScope($withdrawal, 'fiat_withdrawals.user_id');

        $withdrawal->orderByLatest();

        return $this->paginateAndAppendAdminData($withdrawal);
    }

    protected function applyAdminFilters($withdrawal, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $keyword = mb_strtolower($search);
            $like = '%' . $keyword . '%';

            $withdrawal->where(function ($query) use ($like, $search) {
                $query->whereRaw("LOWER(COALESCE(fiat_withdrawals.withdrawal_id, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.type, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(CAST(fiat_withdrawals.status AS TEXT)) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.note, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.rejected_reason, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.account_holder_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.account_holder_address, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.address, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.iban, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.swift, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.ifsc, '')) LIKE ?", [$like])
                    ->orWhereRaw("CAST(fiat_withdrawals.amount AS TEXT) LIKE ?", ['%' . $search . '%'])
                    ->orWhereRaw("CAST(fiat_withdrawals.fee AS TEXT) LIKE ?", ['%' . $search . '%']);

                if (is_numeric($search)) {
                    $query->orWhere('fiat_withdrawals.user_id', (int) $search);
                }

                if ($this->columnExists('fiat_withdrawals', 'bank_account_id') && is_numeric($search)) {
                    $query->orWhere('fiat_withdrawals.bank_account_id', (int) $search);
                }

                $query->orWhereHas('user', function ($userQuery) use ($like, $search) {
                    $userQuery->whereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$like]);

                    if (is_numeric($search)) {
                        $userQuery->orWhere('id', (int) $search);
                    }
                });

                $query->orWhereHas('currency', function ($currencyQuery) use ($like) {
                    $currencyQuery->whereRaw("LOWER(COALESCE(symbol, '')) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$like]);
                });
            });
        }

        if (!empty($filters['type'])) {
            $withdrawal->where('fiat_withdrawals.type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $withdrawal->where('fiat_withdrawals.status', $this->normalizeStatusValue($filters['status']));
        }

        if (!empty($filters['payment_method'])) {
            $paymentMethod = (string) $filters['payment_method'];

            $withdrawal->where(function ($query) use ($paymentMethod) {
                $query->where('fiat_withdrawals.type', $paymentMethod)
                    ->orWhere('fiat_withdrawals.note', 'like', '%"payout_method":"' . $paymentMethod . '"%')
                    ->orWhere('fiat_withdrawals.note', 'like', '%"payment_method":"' . $paymentMethod . '"%')
                    ->orWhere('fiat_withdrawals.note', 'like', '%"payment":"' . $paymentMethod . '"%');
            });
        }

        if (!empty($filters['user_id'])) {
            $withdrawal->where('fiat_withdrawals.user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['referrer'])) {
            $withdrawal->where('fiat_withdrawals.user_id', (int) $filters['referrer']);
        }

        if (!empty($filters['withdrawal_id'])) {
            $withdrawalId = mb_strtolower(trim((string) $filters['withdrawal_id']));
            $withdrawal->whereRaw("LOWER(COALESCE(fiat_withdrawals.withdrawal_id, '')) LIKE ?", ['%' . $withdrawalId . '%']);
        }

        if (!empty($filters['currency'])) {
            $currency = mb_strtolower(trim((string) $filters['currency']));

            $withdrawal->where(function ($query) use ($currency) {
                $query->whereHas('currency', function ($currencyQuery) use ($currency) {
                    $currencyQuery->whereRaw("LOWER(COALESCE(symbol, '')) LIKE ?", ['%' . $currency . '%'])
                        ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", ['%' . $currency . '%']);
                })->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.note, '')) LIKE ?", ['%"crypto_symbol":"%' . $currency . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.note, '')) LIKE ?", ['%"fiat_symbol":"%' . $currency . '%']);
            });
        }

        if (!empty($filters['bank'])) {
            $bank = mb_strtolower(trim((string) $filters['bank']));

            $withdrawal->where(function ($query) use ($bank) {
                $query->whereRaw("LOWER(COALESCE(fiat_withdrawals.account_holder_name, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.account_holder_address, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.name, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.address, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.iban, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.swift, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.ifsc, '')) LIKE ?", ['%' . $bank . '%'])
                    ->orWhereRaw("LOWER(COALESCE(fiat_withdrawals.note, '')) LIKE ?", ['%' . $bank . '%']);

                if ($this->columnExists('fiat_withdrawals', 'bank_account_id') && is_numeric($bank)) {
                    $query->orWhere('fiat_withdrawals.bank_account_id', (int) $bank);
                }

                if ($this->tableExists('bank_accounts')) {
                    $query->orWhereExists(function ($bankQuery) use ($bank) {
                        $bankQuery->selectRaw('1')
                            ->from('bank_accounts')
                            ->where(function ($joinWhere) {
                                if ($this->columnExists('fiat_withdrawals', 'bank_account_id')) {
                                    $joinWhere->whereColumn('bank_accounts.id', 'fiat_withdrawals.bank_account_id');
                                }

                                $joinWhere->orWhereRaw("fiat_withdrawals.note LIKE CONCAT('%\"bank_account_id\":', bank_accounts.id, '%')")
                                    ->orWhereRaw("fiat_withdrawals.note LIKE CONCAT('%\"bank_account_id\":\"', bank_accounts.id, '\"%')");
                            })
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
            $withdrawal->whereBetween('fiat_withdrawals.created_at', [$start, $end]);
        }
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
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);

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

    protected function paginateAndAppendAdminData($withdrawal)
    {
        $withdrawals = $withdrawal->paginate($this->getPerPage())->withQueryString();

        $withdrawals->getCollection()->transform(function ($item) {
            return $this->appendAdminDisplayData($item);
        });

        return $withdrawals;
    }

    protected function appendAdminDisplayData($item)
    {
        $note = $this->decodeJsonValue($item->note);

        $bankAccountId = $item->bank_account_id
            ?? ($note['bank_account_id'] ?? null);

        $bankAccount = $this->getBankAccountFromTable($bankAccountId, (int) $item->user_id);

        $snapshot = !empty($bankAccount) ? $bankAccount : [];

        $paymentMethod = $item->type
            ?? ($note['payout_method'] ?? null)
            ?? ($note['payment_method'] ?? null)
            ?? ($note['payment'] ?? null);

        $bankName = $this->pickValue($snapshot, [
            'bank_name',
            'bank',
            'bank_code',
            'institution_name',
            'branch_name',
        ]) ?: ($item->name ?? null);

        $bankAddress = $this->pickValue($snapshot, [
            'bank_address',
            'address',
            'branch_address',
        ]) ?: ($item->address ?? null);

        $bankNumber = $this->pickValue($snapshot, [
            'iban',
            'account_number',
            'card_number',
            'card_no',
            'number',
        ]) ?: ($item->iban ?? null);

        $bic = $this->pickValue($snapshot, [
            'bic',
            'swift',
            'swift_code',
            'bank_swift',
        ]) ?: ($item->swift ?? null);

        $ifsc = $this->pickValue($snapshot, [
            'ifsc',
            'routing_number',
            'branch_code',
            'sort_code',
        ]) ?: ($item->ifsc ?? null);

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

        if ($holderName === '') {
            $holderName = (string) ($item->account_holder_name ?? '');
        }

        $accountHolderAddress = $this->pickValue($snapshot, [
            'account_holder_address',
            'holder_address',
            'address',
        ]) ?: ($item->account_holder_address ?? null);

        $countryName = optional($item->country)->name;

        if (!$countryName) {
            $countryName = $this->pickValue($snapshot, [
                'country_name',
                'country',
            ]);
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
            'account_holder_name' => $holderName ?: null,
            'account_holder_address' => $accountHolderAddress ?: null,
            'bank_name' => $bankName ?: null,
            'bank_address' => $bankAddress ?: null,
            'country_name' => $countryName ?: null,
            'account_number' => $bankNumber ?: null,
            'iban' => $bankNumber ?: null,
            'bic' => $bic ?: null,
            'swift' => $bic ?: null,
            'ifsc' => $ifsc ?: null,
            'source' => !empty($bankAccount) ? 'bank_accounts' : 'fiat_withdrawals',
        ];

        $item->admin_withdrawal_detail = [
            'order_id' => $note['order_id'] ?? $item->withdrawal_id,
            'source' => $note['source'] ?? null,

            'crypto_currency_id' => $note['crypto_currency_id'] ?? $item->currency_id,
            'crypto_symbol' => $note['crypto_symbol'] ?? optional($item->currency)->symbol,
            'crypto_amount' => $note['crypto_amount'] ?? $item->amount,

            'fiat_currency_id' => $note['fiat_currency_id'] ?? null,
            'fiat_symbol' => $note['fiat_symbol'] ?? null,
            'gross_fiat_amount' => $note['gross_fiat_amount'] ?? null,
            'final_fiat_amount' => $note['final_fiat_amount'] ?? null,

            'fee_percent' => $note['fee_percent'] ?? null,
            'fee_crypto_amount' => $note['fee_crypto_amount'] ?? $item->fee,
            'fee_fiat_amount' => $note['fee_fiat_amount'] ?? null,

            'exchange_rate' => $note['exchange_rate'] ?? null,
            'price_in_fiat' => $note['price_in_fiat'] ?? null,
            'price_in_usdt' => $note['price_in_usdt'] ?? null,
            'usdt_to_fiat_rate' => $note['usdt_to_fiat_rate'] ?? null,
            'locked_from_account' => $note['locked_from_account'] ?? null,
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

    protected function columnExists(string $table, string $column): bool
    {
        try {
            return DB::getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function getPerPage(): int
    {
        $perPage = (int) request()->get('per_page', 100);

        if ($perPage <= 0) {
            return 100;
        }

        return min($perPage, 500);
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
            'pending' => defined('FIAT_WITHDRAWAL_PENDING') ? FIAT_WITHDRAWAL_PENDING : 'pending',
            'confirmed' => defined('FIAT_WITHDRAWAL_CONFIRMED') ? FIAT_WITHDRAWAL_CONFIRMED : 'confirmed',
            'rejected' => defined('FIAT_WITHDRAWAL_REJECTED') ? FIAT_WITHDRAWAL_REJECTED : 'rejected',
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

    public function getReportUser(User $user, $pagination = true)
    {
        $withdrawal = FiatWithdrawal::query();

        $withdrawal->filterUser(request()->only(['currency', 'status']))->orderByLatest();

        $withdrawal->has('currency');

        $withdrawal->with(['currency']);

        if ($this->canAccessUser((int) $user->id)) {
            $withdrawal->where('user_id', $user->id);
        } else {
            $withdrawal->whereRaw('1 = 0');
        }

        if (!$pagination) {
            $withdrawal->limit(15);

            return $withdrawal->get();
        }

        return $withdrawal->paginate(50)->withQueryString();
    }

    public function count()
    {
        $withdrawal = FiatWithdrawal::query();

        return $withdrawal->count();
    }

    public function getWithdrawal($id)
    {
        return FiatWithdrawal::with('currency')->whereId($id)->first();
    }

    public function store($data)
    {
        return $this->withdrawal->create($data);
    }

    public function update($withdrawal, $data)
    {
        return $withdrawal->update($data);
    }

    /**
     * Moderate Fiat Withdrawal
     */
    public function moderate($withdrawal, $action)
    {
        DB::transaction(function () use ($withdrawal, $action) {
            $walletRepository = new WalletRepository();
            $walletService = new WalletService();
            $currencyService = new CurrencyService();

            $wallet = $walletRepository->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency_id, false);

            $amount = $withdrawal->amount;
            $note = request()->get('note') ?? '';
            $isVirtualWithdrawal = $this->isVirtualWithdrawalUser($withdrawal);

            if ($action == "approve") {
                if ($isVirtualWithdrawal) {
                    $this->approveVirtualWithdrawal($withdrawal, $wallet, $note);

                    return;
                }

                if ($withdrawal->type == NETWORK_PAYEER_SLUG) {
                    $amount = math_formatter(math_sub($withdrawal->amount, $withdrawal->fee), 2);

                    $payeer = new Payeer();

                    $response = $payeer->withdraw([
                        'curIn' => 'USD',
                        'sum' => $amount,
                        'curOut' => 'USD',
                        'to' => $withdrawal->account_holder_address,
                        'comment' => __('Transfer') . '#' . $withdrawal->withdrawal_id,
                    ]);

                    if (!empty($response['errors'])) {
                        Log::info($response['errors']);

                        $errorMsg = __('System error. Please contact our support center');

                        $withdrawal->status = FIAT_WITHDRAWAL_REJECTED;
                        $withdrawal->rejected_at = Carbon::now();
                        $withdrawal->rejected_reason = $errorMsg;

                        $walletService->increase($wallet, $withdrawal->amount, 'wallet');
                        $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                        $withdrawal->save();

                        Mail::to($withdrawal->user)->queue(new WithdrawalRejected($withdrawal->user, math_formatter($withdrawal->amount, $withdrawal->currency->decimals), $withdrawal->currency->symbol, $withdrawal->rejected_reason));

                        return;
                    } else {
                        $note = "Transaction ID: " . $withdrawal->withdrawal_id . ". " . $note;
                    }
                } elseif ($withdrawal->type == NETWORK_PERFECT_MONEY_SLUG) {
                    $amount = math_formatter(math_sub($withdrawal->amount, $withdrawal->fee), 2);

                    $perfect = new PerfectMoney();

                    $response = $perfect->withdraw([
                        'amount' => $amount,
                        'account' => $withdrawal->account_holder_address,
                        'id' => $withdrawal->withdrawal_id,
                    ]);

                    if (isset($response['error'])) {
                        Log::info($response['error']);

                        $errorMsg = __('System error. Please contact our support center');

                        if (str_contains($response['error'], 'Invalid Payee_Account')) {
                            $errorMsg = __('Wrong USD Account was provided');
                        }

                        $withdrawal->status = FIAT_WITHDRAWAL_REJECTED;
                        $withdrawal->rejected_at = Carbon::now();
                        $withdrawal->rejected_reason = $errorMsg;

                        $walletService->increase($wallet, $withdrawal->amount, 'wallet');
                        $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                        $withdrawal->save();

                        Mail::to($withdrawal->user)->queue(new WithdrawalRejected($withdrawal->user, math_formatter($withdrawal->amount, $withdrawal->currency->decimals), $withdrawal->currency->symbol, $withdrawal->rejected_reason));

                        return;
                    } else {
                        $note = "Transaction ID: " . $withdrawal->withdrawal_id . ". " . $note;
                    }
                }

                $withdrawal->status = FIAT_WITHDRAWAL_CONFIRMED;
                $withdrawal->approved_at = Carbon::now();
                $withdrawal->note = nl2br($note);

                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                $currencyService->decrease($withdrawal->currency, $withdrawal->amount);
                $withdrawal->currency->wallet_balance_updated_at = Carbon::now();
                $withdrawal->currency->update();

                Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed($withdrawal->user, math_formatter($amount, $withdrawal->currency->decimals), $withdrawal->currency->symbol, $withdrawal->note));
            } else {
                if ($isVirtualWithdrawal) {
                    $this->rejectVirtualWithdrawal($withdrawal, $wallet);

                    return;
                }

                $withdrawal->status = FIAT_WITHDRAWAL_REJECTED;
                $withdrawal->rejected_at = Carbon::now();
                $withdrawal->rejected_reason = nl2br((string) request()->get('reason', ''));

                $walletService->increase($wallet, $withdrawal->amount, 'wallet');
                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                Mail::to($withdrawal->user)->queue(new WithdrawalRejected($withdrawal->user, math_formatter($withdrawal->amount, $withdrawal->currency->decimals), $withdrawal->currency->symbol, $withdrawal->rejected_reason));
            }

            $withdrawal->save();
        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    protected function isVirtualWithdrawalUser(FiatWithdrawal $withdrawal): bool
    {
        $withdrawal->loadMissing('user');

        $user = $withdrawal->user;

        if (!$user) {
            return false;
        }

        return filter_var($user->is_xn ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($user->is_xm ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function approveVirtualWithdrawal(FiatWithdrawal $withdrawal, $wallet, string $note = ''): void
    {
        $this->decreaseLockedWithdrawalBalance($wallet, $withdrawal->amount);

        $note = trim($note);
        $virtualNote = 'Virtual account withdrawal approved without external payout.';

        $withdrawal->status = FIAT_WITHDRAWAL_CONFIRMED;
        $withdrawal->approved_at = Carbon::now();
        $withdrawal->note = nl2br($note !== '' ? $virtualNote . ' ' . $note : $virtualNote);
        $withdrawal->save();

        try {
            Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed(
                $withdrawal->user,
                math_formatter($withdrawal->amount, $withdrawal->currency->decimals),
                $withdrawal->currency->symbol,
                $withdrawal->note
            ));
        } catch (\Exception $e) {
        }
    }

    protected function rejectVirtualWithdrawal(FiatWithdrawal $withdrawal, $wallet): void
    {
        $withdrawal->status = FIAT_WITHDRAWAL_REJECTED;
        $withdrawal->rejected_at = Carbon::now();
        $withdrawal->rejected_reason = nl2br((string) request()->get('reason', ''));
        $withdrawal->save();

        $this->releaseLockedWithdrawalBalance($wallet, $withdrawal->amount);

        try {
            Mail::to($withdrawal->user)->queue(new WithdrawalRejected(
                $withdrawal->user,
                math_formatter($withdrawal->amount, $withdrawal->currency->decimals),
                $withdrawal->currency->symbol,
                $withdrawal->rejected_reason
            ));
        } catch (\Exception $e) {
        }
    }

    protected function decreaseLockedWithdrawalBalance($wallet, $amount): void
    {
        $this->consumeLockedWithdrawalBalance($wallet, $amount, false);
    }

    protected function releaseLockedWithdrawalBalance($wallet, $amount): void
    {
        $this->consumeLockedWithdrawalBalance($wallet, $amount, true);
    }

    protected function consumeLockedWithdrawalBalance($wallet, $amount, bool $releaseToWallet): void
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
            if (!$this->walletColumnExists($pair['withdraw'])) {
                continue;
            }

            if ($releaseToWallet && (!$pair['wallet'] || !$this->walletColumnExists($pair['wallet']))) {
                continue;
            }

            $available = $this->normalizeDecimal($freshWallet->{$pair['withdraw']} ?? 0);

            if (math_compare($available, $amount) >= 0) {
                $this->moveWalletAmount($freshWallet, $pair['withdraw'], $releaseToWallet ? $pair['wallet'] : null, $amount);

                return;
            }
        }

        $remaining = $amount;

        foreach ($pairs as $pair) {
            if (!$this->walletColumnExists($pair['withdraw'])) {
                continue;
            }

            if ($releaseToWallet && (!$pair['wallet'] || !$this->walletColumnExists($pair['wallet']))) {
                continue;
            }

            $freshWallet->refresh();
            $available = $this->normalizeDecimal($freshWallet->{$pair['withdraw']} ?? 0);

            if (math_compare($available, 0) <= 0) {
                continue;
            }

            $deduct = math_compare($available, $remaining) >= 0 ? $remaining : $available;

            $this->moveWalletAmount($freshWallet, $pair['withdraw'], $releaseToWallet ? $pair['wallet'] : null, $deduct);

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

    protected function moveWalletAmount(Wallet $wallet, string $fromColumn, ?string $toColumn, $amount): void
    {
        $amount = $this->normalizeDecimal($amount);

        if (math_compare($amount, 0) <= 0 || !$this->walletColumnExists($fromColumn)) {
            return;
        }

        if ($toColumn && $this->walletColumnExists($toColumn)) {
            DB::statement(
                "UPDATE wallets
                 SET {$fromColumn} = GREATEST(COALESCE({$fromColumn}, 0) - ?, 0),
                     {$toColumn} = COALESCE({$toColumn}, 0) + ?,
                     updated_at = ?
                 WHERE id = ?",
                [$amount, $amount, now(), $wallet->id]
            );

            return;
        }

        DB::statement(
            "UPDATE wallets
             SET {$fromColumn} = GREATEST(COALESCE({$fromColumn}, 0) - ?, 0),
                 updated_at = ?
             WHERE id = ?",
            [$amount, now(), $wallet->id]
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

    public function processWithdraw($data, $currency)
    {
        return DB::transaction(function () use ($data, $currency) {
            $this->store($data);

            $wallet = (new WalletRepository())->getWalletByCurrency($data['user_id'], $currency->id, true);

            $this->reserveWithdrawalBalance(
                $wallet,
                $data['amount'],
                $this->isVirtualUserId((int) $data['user_id'])
            );

            $adminEmail = Setting::get('notification.admin_email', false);
            $notificationAllowed = Setting::get('notification.fiat_withdrawals', false);

            if ($adminEmail && $notificationAllowed) {
                $route = route('admin.reports.withdrawals.fiat') . "?search=" . $data['withdrawal_id'];
                Mail::to($adminEmail)->queue(new AdminWithdrawalReceived(math_formatter($data['amount'], $currency->decimals), $currency->symbol, $route));
            }

            return true;
        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    protected function reserveWithdrawalBalance($wallet, $amount, bool $allowVirtual): void
    {
        $remaining = $this->normalizeDecimal($amount);

        if (!$wallet || math_compare($remaining, 0) <= 0) {
            return;
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            throw new \Exception('Wallet not found');
        }

        $pairs = $this->getWithdrawalReservePairs($allowVirtual);

        foreach ($pairs as $pair) {
            if (!$this->walletColumnExists($pair['from']) || !$this->walletColumnExists($pair['to'])) {
                continue;
            }

            $freshWallet->refresh();
            $available = $this->normalizeDecimal($freshWallet->{$pair['from']} ?? 0);

            if (math_compare($available, 0) <= 0) {
                continue;
            }

            $deduct = math_compare($available, $remaining) >= 0 ? $remaining : $available;

            $this->moveWalletAmount($freshWallet, $pair['from'], $pair['to'], $deduct);

            $remaining = math_sub($remaining, $deduct);

            if (math_compare($remaining, 0) <= 0) {
                return;
            }
        }

        throw new \Exception('Insufficient balance');
    }

    protected function getWithdrawalReservePairs(bool $allowVirtual): array
    {
        $pairs = [];

        if ($allowVirtual) {
            $virtualWithdrawTarget = $this->walletColumnExists('balance_in_virtual_withdraw')
                ? 'balance_in_virtual_withdraw'
                : ($this->walletColumnExists('balance_in_withdraw') ? 'balance_in_withdraw' : null);

            if ($virtualWithdrawTarget && $this->walletColumnExists('balance_in_virtual_wallet')) {
                $pairs[] = [
                    'from' => 'balance_in_virtual_wallet',
                    'to' => $virtualWithdrawTarget,
                ];
            }
        }

        if ($this->walletColumnExists('balance_in_wallet') && $this->walletColumnExists('balance_in_withdraw')) {
            $pairs[] = [
                'from' => 'balance_in_wallet',
                'to' => 'balance_in_withdraw',
            ];
        }

        return $pairs;
    }

    protected function isVirtualUserId(int $userId): bool
    {
        $columns = ['id'];

        if ($this->userColumnExists('is_xn')) {
            $columns[] = 'is_xn';
        }

        if ($this->userColumnExists('is_xm')) {
            $columns[] = 'is_xm';
        }

        $user = User::query()
            ->select($columns)
            ->where('id', $userId)
            ->first();

        if (!$user) {
            return false;
        }

        return filter_var($user->is_xn ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($user->is_xm ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function userColumnExists(string $column): bool
    {
        try {
            return Schema::hasColumn('users', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
