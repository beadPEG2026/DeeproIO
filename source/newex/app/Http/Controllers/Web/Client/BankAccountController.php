<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BankAccountController extends Controller
{
    private const BANK_ACCOUNTS_TABLE = 'bank_accounts';

    private const STATUS_PENDING = 0;
    private const STATUS_APPROVED = 1;
    private const STATUS_REJECTED = 2;

    public function kyc(Request $request)
    {
        $user = auth()->user();

        if ($user && Schema::hasColumn('users', 'bank_verified')) {
            $user->bank_verified = 2;
            $user->save();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('Bank verification skipped'),
            ]);
        }

        return response()->redirectTo('/user/profile/bank_accounts');
    }

    public function ipn(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Bank IPN ignored',
        ]);
    }

    public function plaid_token()
    {
        return response()->json([
            'success' => false,
            'message' => __('Plaid is disabled. Please submit bank account manually.'),
        ], 410);
    }

    public function countries()
    {
        if (!Schema::hasTable('countries')) {
            return response()->json([
                'countries' => [],
            ]);
        }

        $countries = DB::table('countries')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'countries' => $countries,
        ]);
    }

    public function link_account(Request $request)
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name'  => ['required', 'string', 'max:120'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'iban'       => ['required', 'string', 'max:120'],
            'bic'        => ['required', 'string', 'max:120'],
        ], [
            'first_name.required' => __('First name is required'),
            'last_name.required'  => __('Last name is required'),
            'country_id.required' => __('Country is required'),
            'country_id.exists'   => __('Selected country is invalid'),
            'iban.required'       => __('Bank Account (IBAN) is required'),
            'bic.required'        => __('Bank Code (BIC) is required'),
        ]);

        if (!Schema::hasTable(self::BANK_ACCOUNTS_TABLE)) {
            return response()->json([
                'success' => false,
                'message' => __('bank_accounts table does not exist'),
            ], 500);
        }

        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
            ], 401);
        }

        $userColumn = $this->getBankAccountUserColumn();

        if (!$userColumn) {
            return response()->json([
                'success' => false,
                'message' => __('bank_accounts table must have user_id or uid column'),
            ], 500);
        }

        $firstName = trim((string) $request->input('first_name'));
        $lastName = trim((string) $request->input('last_name'));
        $holderName = trim($firstName . ' ' . $lastName);

        $countryId = (int) $request->input('country_id');
        $iban = $this->normalizeBankCode($request->input('iban'));
        $bic = $this->normalizeBankCode($request->input('bic'));

        if ($iban === '') {
            return response()->json([
                'success' => false,
                'message' => __('Bank Account (IBAN) is required'),
                'errors' => [
                    'iban' => [__('Bank Account (IBAN) is required')],
                ],
            ], 422);
        }

        if ($bic === '') {
            return response()->json([
                'success' => false,
                'message' => __('Bank Code (BIC) is required'),
                'errors' => [
                    'bic' => [__('Bank Code (BIC) is required')],
                ],
            ], 422);
        }

        return DB::transaction(function () use (
            $user,
            $userColumn,
            $firstName,
            $lastName,
            $holderName,
            $countryId,
            $iban,
            $bic
        ) {
            /*
             * 只要用户存在“审核中”或“已审核”的银行卡，就不允许再提交。
             * 如果用户只有“拒绝”的银行卡，则允许重新提交。
             */
            $blockingQuery = DB::table(self::BANK_ACCOUNTS_TABLE)
                ->where($userColumn, $user->id)
                ->lockForUpdate();

            $this->wherePendingOrApproved($blockingQuery);

            $blockingAccount = $blockingQuery->first();

            if ($blockingAccount) {
                $status = $this->normalizeBankStatusFromRow($blockingAccount);

                return response()->json([
                    'success' => false,
                    'message' => $status === 'approved'
                        ? __('Your bank account has been approved. You cannot submit another bank account.')
                        : __('Your bank account is under review. Please wait for the review result.'),
                    'errors' => [
                        'iban' => [$status === 'approved'
                            ? __('Your bank account has been approved. You cannot submit another bank account.')
                            : __('Your bank account is under review. Please wait for the review result.')],
                    ],
                ], 422);
            }

            if ($this->hasBankAccountColumn('iban')) {
                $ibanExists = DB::table(self::BANK_ACCOUNTS_TABLE)
                    ->where('iban', $iban)
                    ->lockForUpdate();

                $this->wherePendingOrApproved($ibanExists);

                if ($ibanExists->first()) {
                    return response()->json([
                        'success' => false,
                        'message' => __('This bank account has already been submitted.'),
                        'errors' => [
                            'iban' => [__('This bank account has already been submitted.')],
                        ],
                    ], 422);
                }
            } elseif ($this->hasBankAccountColumn('account_number')) {
                $ibanExists = DB::table(self::BANK_ACCOUNTS_TABLE)
                    ->where('account_number', $iban)
                    ->lockForUpdate();

                $this->wherePendingOrApproved($ibanExists);

                if ($ibanExists->first()) {
                    return response()->json([
                        'success' => false,
                        'message' => __('This bank account has already been submitted.'),
                        'errors' => [
                            'iban' => [__('This bank account has already been submitted.')],
                        ],
                    ], 422);
                }
            }

            $columns = Schema::getColumnListing(self::BANK_ACCOUNTS_TABLE);
            $data = [];

            $put = function ($column, $value) use (&$data, $columns) {
                if (in_array($column, $columns, true)) {
                    $data[$column] = $value;
                }
            };

            $put($userColumn, $user->id);

            $put('first_name', $firstName);
            $put('last_name', $lastName);

            $put('account_holder_name', $holderName);
            $put('account_name', $holderName);
            $put('name', $holderName);

            $put('country_id', $countryId);

            $put('iban', $iban);
            $put('account_number', $iban);

            $put('bic', $bic);
            $put('swift', $bic);

            $put('bank_name', '');
            $put('reference', '');
            $put('address', '');
            $put('account_holder_address', '');
            $put('ifsc', '');

            $put('account_type', 'iban');
            $put('type', 'iban');

            /*
             * 新提交的银行卡默认进入审核中。
             * status_type: 0 审核中, 1 已审核, 2 拒绝。
             */
            $put('status_type', self::STATUS_PENDING);
            $put('status', $this->getBooleanLikeValue('status', false));
            $put('enabled', $this->getBooleanLikeValue('enabled', true));
            $put('is_enabled', $this->getBooleanLikeValue('is_enabled', true));

            $put('created_at', now());
            $put('updated_at', now());

            DB::table(self::BANK_ACCOUNTS_TABLE)->insert($data);

            return response()->json([
                'success' => true,
                'message' => __('Bank account submitted successfully. Please wait for review.'),
            ]);
        });
    }

    public function delete_account(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => __('Bank account cannot be deleted after submission.'),
        ], 422);
    }

    public function getAccounts()
    {
        if (!Schema::hasTable(self::BANK_ACCOUNTS_TABLE)) {
            return response()->json([
                'accounts' => [],
            ]);
        }

        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'accounts' => [],
            ]);
        }

        $userColumn = $this->getBankAccountUserColumn();

        if (!$userColumn) {
            return response()->json([
                'accounts' => [],
            ]);
        }

        $query = DB::table(self::BANK_ACCOUNTS_TABLE . ' as ba')
            ->where('ba.' . $userColumn, $user->id)
            ->orderByDesc('ba.id');

        if (
            Schema::hasTable('countries') &&
            Schema::hasColumn(self::BANK_ACCOUNTS_TABLE, 'country_id')
        ) {
            $query->leftJoin('countries as c', 'c.id', '=', 'ba.country_id')
                ->select('ba.*', 'c.name as country_name');
        } else {
            $query->select('ba.*');
        }

        $allAccounts = $query->get();

        $pendingOrApproved = $allAccounts->filter(function ($account) {
            return in_array($this->normalizeBankStatusFromRow($account), ['pending', 'approved'], true);
        })->values();

        /*
         * 有审核中或已审核记录时，不再显示旧的拒绝记录。
         * 没有审核中/已审核，且有拒绝记录时，只显示最新一条拒绝记录，方便用户知道可以重新提交。
         */
        if ($pendingOrApproved->isNotEmpty()) {
            $displayAccounts = $pendingOrApproved;
        } else {
            $displayAccounts = $allAccounts->filter(function ($account) {
                return $this->normalizeBankStatusFromRow($account) === 'rejected';
            })->take(1)->values();
        }

        $accounts = $displayAccounts
            ->map(function ($account) {
                $iban = $this->firstAvailableValue($account, [
                    'iban',
                    'account_number',
                ]);

                $bic = $this->firstAvailableValue($account, [
                    'bic',
                    'swift',
                ]);

                $firstName = $this->firstAvailableValue($account, ['first_name']);
                $lastName = $this->firstAvailableValue($account, ['last_name']);

                $holderName = trim($firstName . ' ' . $lastName);

                if ($holderName === '') {
                    $holderName = $this->firstAvailableValue($account, [
                        'account_holder_name',
                        'account_name',
                        'name',
                    ]);
                }

                $status = $this->normalizeBankStatusFromRow($account);

                return [
                    'id' => $account->id ?? null,
                    'number' => $account->id ?? null,
                    'accountNumber' => $this->maskIban($iban),
                    'accountName' => $holderName,
                    'countryName' => $account->country_name ?? '',
                    'iban' => $iban,
                    'bic' => $bic,
                    'accountType' => 'IBAN',
                    'accountStatus' => $status,
                    'statusType' => $account->status_type ?? null,
                    'created_at' => $account->created_at ?? null,
                ];
            })
            ->values();

        return response()->json([
            'accounts' => $accounts,
        ]);
    }

    public function getTransactions()
    {
        return response()->json([
            'transactions' => [],
        ]);
    }

    public function issueSila(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => __('Sila ACH is disabled.'),
        ], 410);
    }

    private function wherePendingOrApproved($query): void
    {
        if ($this->hasBankAccountColumn('status_type')) {
            $query->whereIn('status_type', [self::STATUS_PENDING, self::STATUS_APPROVED]);
            return;
        }

        if ($this->hasBankAccountColumn('status')) {
            $query->where(function ($statusQuery) {
                $statusQuery->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', '1')
                    ->orWhere('status', 'active')
                    ->orWhere('status', 'pending');
            });
        }
    }

    private function normalizeBankStatusFromRow($row): string
    {
        if ($this->hasBankAccountColumn('status_type') && isset($row->status_type)) {
            $statusType = (int) $row->status_type;

            if ($statusType === self::STATUS_APPROVED) {
                return 'approved';
            }

            if ($statusType === self::STATUS_REJECTED) {
                return 'rejected';
            }

            return 'pending';
        }

        return $this->normalizeBankStatus($row->status ?? null);
    }

    private function getBankAccountUserColumn()
    {
        if (!Schema::hasTable(self::BANK_ACCOUNTS_TABLE)) {
            return null;
        }

        if (Schema::hasColumn(self::BANK_ACCOUNTS_TABLE, 'user_id')) {
            return 'user_id';
        }

        if (Schema::hasColumn(self::BANK_ACCOUNTS_TABLE, 'uid')) {
            return 'uid';
        }

        if (Schema::hasColumn(self::BANK_ACCOUNTS_TABLE, 'client_id')) {
            return 'client_id';
        }

        return null;
    }

    private function hasBankAccountColumn($column)
    {
        return Schema::hasTable(self::BANK_ACCOUNTS_TABLE)
            && Schema::hasColumn(self::BANK_ACCOUNTS_TABLE, $column);
    }

    private function getBankAccountColumnType($column)
    {
        if (
            !Schema::hasTable(self::BANK_ACCOUNTS_TABLE) ||
            !Schema::hasColumn(self::BANK_ACCOUNTS_TABLE, $column)
        ) {
            return null;
        }

        try {
            return Schema::getColumnType(self::BANK_ACCOUNTS_TABLE, $column);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getBooleanLikeValue($column, $value)
    {
        $type = $this->getBankAccountColumnType($column);

        if (in_array($type, ['boolean', 'bool'], true)) {
            return (bool) $value;
        }

        if (in_array($type, ['integer', 'bigint', 'smallint'], true)) {
            return $value ? 1 : 0;
        }

        return $value ? '1' : '0';
    }

    private function normalizeBankStatus($status)
    {
        if (
            $status === true ||
            $status === 1 ||
            $status === '1' ||
            $status === 't' ||
            $status === 'true' ||
            $status === 'active' ||
            $status === 'approved'
        ) {
            return 'approved';
        }

        if (
            $status === 2 ||
            $status === '2' ||
            $status === 'rejected' ||
            $status === 'reject'
        ) {
            return 'rejected';
        }

        return 'pending';
    }

    private function normalizeBankCode($value)
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $value)));
    }

    private function firstAvailableValue($row, array $columns)
    {
        foreach ($columns as $column) {
            if (isset($row->{$column}) && $row->{$column} !== null && $row->{$column} !== '') {
                return (string) $row->{$column};
            }
        }

        return '';
    }

    private function maskIban($iban)
    {
        $iban = (string) $iban;

        if ($iban === '') {
            return '';
        }

        $length = strlen($iban);

        if ($length <= 8) {
            return str_repeat('*', max(0, $length - 4)) . substr($iban, -4);
        }

        return substr($iban, 0, 4) . ' **** **** ' . substr($iban, -4);
    }
}
