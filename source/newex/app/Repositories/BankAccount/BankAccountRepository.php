<?php

namespace App\Repositories\BankAccount;

use App\Models\BankAccount\BankAccount;
use Illuminate\Support\Facades\DB;

class BankAccountRepository
{
    /**
     * @var BankAccount
     */
    protected $bankAccount;

    /**
     * BankAccountRepository constructor.
     */
    public function __construct()
    {
        $this->bankAccount = new BankAccount();
    }

    public function get(array $filters = [])
    {
        $bankAccounts = BankAccount::query();

        /*
         * 不展示系统账户。
         * 你的 bank_accounts 表使用 user_id 字段：
         * user_id = 0 表示系统账户，不在后台银行卡审核列表显示。
         */
        if ($this->columnExists('bank_accounts', 'user_id')) {
            $bankAccounts->where('bank_accounts.user_id', '>', 0);
        } elseif ($this->columnExists('bank_accounts', 'uid')) {
            $bankAccounts->where('bank_accounts.uid', '>', 0);
        }

        $this->applyFilters($bankAccounts, $filters);

        $bankAccounts->orderBy('id', 'desc');

        $accounts = $bankAccounts
            ->paginate($this->getPerPage($filters))
            ->withQueryString();

        $accounts->getCollection()->transform(function ($account) {
            return $this->appendUserInfo($account);
        });

        return $accounts;
    }

    protected function applyFilters($bankAccounts, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $keyword = mb_strtolower($search);
            $like = '%' . $keyword . '%';

            $bankAccounts->where(function ($query) use ($like, $search) {
                /*
                 * 银行账户表自身字段搜索：
                 * 卡号 / 银行名称 / SWIFT / 开户人 / 备注等。
                 */
                foreach ([
                    'reference_number',
                    'name',
                    'iban',
                    'swift',
                    'ifsc',
                    'address',
                    'account_holder_name',
                    'account_holder_address',
                    'note',
                ] as $column) {
                    if ($this->columnExists('bank_accounts', $column)) {
                        $query->orWhereRaw(
                            'LOWER(CAST(COALESCE(bank_accounts.' . $column . ', \'\') AS TEXT)) LIKE ?',
                            [$like]
                        );
                    }
                }

                /*
                 * 数字搜索：
                 * bank_accounts.id
                 * bank_accounts.user_id
                 * bank_accounts.uid
                 */
                if (is_numeric($search)) {
                    $query->orWhere('bank_accounts.id', (int) $search);

                    if ($this->columnExists('bank_accounts', 'user_id')) {
                        $query->orWhere('bank_accounts.user_id', (int) $search);
                    }

                    if ($this->columnExists('bank_accounts', 'uid')) {
                        $query->orWhere('bank_accounts.uid', (int) $search);
                    }
                }

                /*
                 * 用户表搜索：
                 * UID / 邮箱 / 邀请码。
                 */
                if ($this->tableExists('users')) {
                    $userColumn = $this->getBankAccountUserColumn();

                    if ($userColumn) {
                        $query->orWhereExists(function ($userQuery) use ($like, $search, $userColumn) {
                            $userQuery->selectRaw('1')
                                ->from('users')
                                ->whereColumn('users.id', 'bank_accounts.' . $userColumn)
                                ->where(function ($userWhere) use ($like, $search) {
                                    /*
                                     * UID：
                                     * 兼容 users.id 和 users.uid。
                                     */
                                    if (is_numeric($search)) {
                                        $userWhere->orWhere('users.id', (int) $search);

                                        if ($this->columnExists('users', 'uid')) {
                                            $userWhere->orWhere('users.uid', (int) $search);
                                        }
                                    }

                                    /*
                                     * 邮箱。
                                     */
                                    if ($this->columnExists('users', 'email')) {
                                        $userWhere->orWhereRaw(
                                            'LOWER(CAST(COALESCE(users.email, \'\') AS TEXT)) LIKE ?',
                                            [$like]
                                        );
                                    }

                                    /*
                                     * 用户名 / 名称。
                                     */
                                    if ($this->columnExists('users', 'name')) {
                                        $userWhere->orWhereRaw(
                                            'LOWER(CAST(COALESCE(users.name, \'\') AS TEXT)) LIKE ?',
                                            [$like]
                                        );
                                    }

                                    /*
                                     * 邀请码字段兼容。
                                     */
                                    foreach ([
                                        'invite_code',
                                        'invitation_code',
                                        'referral_code',
                                        'ref_code',
                                        'code',
                                    ] as $inviteColumn) {
                                        if ($this->columnExists('users', $inviteColumn)) {
                                            $userWhere->orWhereRaw(
                                                'LOWER(CAST(COALESCE(users.' . $inviteColumn . ', \'\') AS TEXT)) LIKE ?',
                                                [$like]
                                            );
                                        }
                                    }
                                });
                        });
                    }
                }
            });
        }

        if (
            array_key_exists('status_type', $filters) &&
            $filters['status_type'] !== null &&
            $filters['status_type'] !== ''
        ) {
            if ($this->columnExists('bank_accounts', 'status_type')) {
                $bankAccounts->where('bank_accounts.status_type', (int) $filters['status_type']);
            } elseif ($this->columnExists('bank_accounts', 'status')) {
                $bankAccounts->where('bank_accounts.status', (int) $filters['status_type'] === 1);
            }
        }

        if (!empty($filters['user_id'])) {
            $userColumn = $this->getBankAccountUserColumn();

            if ($userColumn) {
                $bankAccounts->where('bank_accounts.' . $userColumn, (int) $filters['user_id']);
            }
        }
    }

    protected function appendUserInfo($account)
    {
        $user = null;
        $userColumn = $this->getBankAccountUserColumn();

        if ($userColumn && !empty($account->{$userColumn}) && $this->tableExists('users')) {
            $dbUser = DB::table('users')
                ->where('id', $account->{$userColumn})
                ->first();

            if ($dbUser) {
                $user = [
                    'id' => $dbUser->id ?? null,
                    'uid' => $dbUser->uid ?? null,
                    'name' => $dbUser->name ?? null,
                    'email' => $dbUser->email ?? null,
                    'invite_code' => $dbUser->invite_code
                        ?? $dbUser->invitation_code
                        ?? $dbUser->referral_code
                        ?? $dbUser->ref_code
                        ?? $dbUser->code
                        ?? null,
                ];
            }
        }

        $account->setAttribute('admin_user', $user);

        return $account;
    }

    protected function getPerPage(array $filters = []): int
    {
        $perPage = (int) ($filters['per_page'] ?? request()->get('per_page', 50));

        if ($perPage <= 0) {
            return 50;
        }

        return min($perPage, 200);
    }

    protected function getBankAccountUserColumn(): ?string
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

    public function updateStatusType($id, int $statusType)
    {
        $data = [
            'updated_at' => now(),
        ];

        if ($this->columnExists('bank_accounts', 'status_type')) {
            $data['status_type'] = $statusType;
        }

        /*
         * 兼容原来的 status 字段：
         * status_type = 1 时才启用。
         * 待审核 / 拒绝时 status 为 false。
         */
        if ($this->columnExists('bank_accounts', 'status')) {
            $data['status'] = $statusType === 1;
        }

        DB::table('bank_accounts')
            ->where('id', $id)
            ->update($data);

        return true;
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

    public function all($onlyActive = false)
    {
        $bankAccounts = BankAccount::query();

        if ($onlyActive) {
            $bankAccounts->active();
        }

        return $bankAccounts->get();
    }

    public function getBankAccountById($id)
    {
        return BankAccount::find($id);
    }

    public function getBankAccountBySlug($slug)
    {
        $bankAccount = BankAccount::query();

        $bankAccount->whereSlug($slug);

        $bankAccount->active();

        return $bankAccount->first();
    }

    public function store($data)
    {
        $data = $this->filterTableColumns('bank_accounts', $data);

        $bankAccount = $this->bankAccount->create($data);

        return $bankAccount->fresh();
    }

    public function update($id, $data)
    {
        $data = $this->filterTableColumns('bank_accounts', $data);

        $bankAccount = BankAccount::find($id);

        if (!$bankAccount) {
            return null;
        }

        $bankAccount->update($data);

        return $bankAccount->fresh();
    }

    public function delete($id)
    {
        $bankAccount = BankAccount::find($id);

        if ($bankAccount) {
            $bankAccount->delete();
        }

        return true;
    }

    protected function filterTableColumns(string $table, array $data): array
    {
        try {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);

            if (empty($columns)) {
                return $data;
            }

            return array_intersect_key($data, array_flip($columns));
        } catch (\Throwable $e) {
            return $data;
        }
    }
}