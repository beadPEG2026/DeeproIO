<?php

namespace App\Services\Staking;

use App\Models\Staking\StakingUser;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StakingWalletService
{
    private const REAL_WALLET_FIELD = 'balance_in_wallet';
    private const VIRTUAL_WALLET_FIELD = 'balance_in_virtual_wallet';

    public function getAvailableBalanceInfo(?User $user, ?Wallet $wallet): array
    {
        if (!$user || !$wallet) {
            return [
                'account_type' => 'real',
                'field' => self::REAL_WALLET_FIELD,
                'balance' => '0',
            ];
        }

        if ($this->isVirtualUser($user)) {
            return [
                'account_type' => 'virtual',
                'field' => self::VIRTUAL_WALLET_FIELD,
                'balance' => $this->walletColumnExists(self::VIRTUAL_WALLET_FIELD)
                    ? $this->normalizeDecimal($wallet->{self::VIRTUAL_WALLET_FIELD} ?? 0)
                    : '0',
            ];
        }

        return [
            'account_type' => 'real',
            'field' => self::REAL_WALLET_FIELD,
            'balance' => $this->normalizeDecimal($wallet->{self::REAL_WALLET_FIELD} ?? 0),
        ];
    }

    public function getDebitSource(User $user, Wallet $wallet, $amount): array
    {
        $amount = $this->normalizeDecimal($amount);

        if (math_compare($amount, 0) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [__('Please enter a valid staking amount.')],
            ]);
        }

        $balanceInfo = $this->getAvailableBalanceInfo($user, $wallet);
        $field = $balanceInfo['field'];

        if (!$this->walletColumnExists($field)) {
            throw ValidationException::withMessages([
                'amount' => [__('Wallet balance field does not exist.')],
            ]);
        }

        if (math_compare($balanceInfo['balance'], $amount) < 0) {
            $message = $balanceInfo['account_type'] === 'virtual'
                ? __('Insufficient virtual wallet balance')
                : __('Insufficient balance');

            throw ValidationException::withMessages([
                'amount' => [$message],
            ]);
        }

        return $balanceInfo;
    }

    public function decrease(Wallet $wallet, string $field, $amount): void
    {
        $this->updateWalletField($wallet, $field, $amount, '-');
    }

    public function increase(Wallet $wallet, string $field, $amount): void
    {
        $this->updateWalletField($wallet, $field, $amount, '+');
    }

    public function getReturnBalanceField(StakingUser $stakingUser): string
    {
        $sourceField = null;

        if (isset($stakingUser->source_balance_field)) {
            $sourceField = $stakingUser->source_balance_field;
        }

        if (!$sourceField && isset($stakingUser->source_field)) {
            $sourceField = $stakingUser->source_field;
        }

        $meta = $this->getStakingMeta($stakingUser);

        if (!$sourceField && isset($meta['source_balance_field'])) {
            $sourceField = $meta['source_balance_field'];
        }

        if (!$sourceField && isset($meta['source_field'])) {
            $sourceField = $meta['source_field'];
        }

        if (!$sourceField && isset($meta['source_account_type']) && $meta['source_account_type'] === 'virtual') {
            $sourceField = self::VIRTUAL_WALLET_FIELD;
        }

        if (!$sourceField && isset($stakingUser->source_account_type) && $stakingUser->source_account_type === 'virtual') {
            $sourceField = self::VIRTUAL_WALLET_FIELD;
        }

        if (!$sourceField && $this->isVirtualUserId((int) $stakingUser->user_id)) {
            $sourceField = self::VIRTUAL_WALLET_FIELD;
        }

        return $this->normalizeWalletField($sourceField ?: self::REAL_WALLET_FIELD);
    }

    public function canStoreStakingMeta(): bool
    {
        return $this->tableColumnExists('staking_users', 'meta');
    }

    public function sourceMeta(array $source): array
    {
        return [
            'source' => 'staking_submit',
            'source_account_type' => $source['account_type'] ?? 'real',
            'source_balance_field' => $source['field'] ?? self::REAL_WALLET_FIELD,
        ];
    }

    public function isVirtualUser(User $user): bool
    {
        return filter_var($user->is_xn ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function getStakingMeta(StakingUser $stakingUser): array
    {
        $meta = $stakingUser->meta ?? [];

        if (is_array($meta)) {
            return $meta;
        }

        if (!$meta) {
            return [];
        }

        $decoded = json_decode((string) $meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function updateWalletField(Wallet $wallet, string $field, $amount, string $operator): void
    {
        $field = $this->normalizeWalletField($field);
        $amount = $this->normalizeDecimal($amount);

        if (math_compare($amount, 0) <= 0) {
            return;
        }

        if ($operator === '-') {
            DB::statement(
                "UPDATE wallets SET {$field} = GREATEST(COALESCE({$field}, 0) - ?, 0), updated_at = ? WHERE id = ?",
                [$amount, now(), $wallet->id]
            );

            return;
        }

        DB::statement(
            "UPDATE wallets SET {$field} = COALESCE({$field}, 0) + ?, updated_at = ? WHERE id = ?",
            [$amount, now(), $wallet->id]
        );
    }

    private function normalizeWalletField($field): string
    {
        $field = (string) $field;

        $allowedFields = [
            self::REAL_WALLET_FIELD,
            self::VIRTUAL_WALLET_FIELD,
        ];

        if (!in_array($field, $allowedFields, true)) {
            return self::REAL_WALLET_FIELD;
        }

        if (!$this->walletColumnExists($field)) {
            return self::REAL_WALLET_FIELD;
        }

        return $field;
    }

    private function isVirtualUserId(int $userId): bool
    {
        if ($userId <= 0 || !$this->tableColumnExists('users', 'is_xn')) {
            return false;
        }

        return DB::table('users')
            ->where('id', $userId)
            ->where('is_xn', true)
            ->exists();
    }

    private function walletColumnExists(string $column): bool
    {
        return $this->tableColumnExists('wallets', $column);
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function normalizeDecimal($value): string
    {
        $value = str_replace(',', '', (string) ($value ?? 0));

        if ($value === '' || !is_numeric($value)) {
            return '0';
        }

        return $value;
    }
}
