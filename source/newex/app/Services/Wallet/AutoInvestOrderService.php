<?php

namespace App\Services\Wallet;

use App\Services\Performance\ReadModelCacheService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AutoInvestOrderService
{
    public function redeemOrder($user, $orderId, string $closeReason = 'redeemed', bool $allowUnmaturedFixed = false): array
    {
        if (!$user) {
            throw new Exception(__('Unauthorized'));
        }

        if (!$orderId) {
            throw new Exception(__('Order ID not found.'));
        }

        $result = DB::transaction(function () use ($user, $orderId, $closeReason, $allowUnmaturedFixed) {
            $orderQuery = DB::table('auto_invest_orders')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->lockForUpdate();

            if (is_numeric($orderId)) {
                $orderQuery->where('id', $orderId);
            } else {
                $orderQuery->where(function ($query) use ($orderId) {
                    $query->where('order_no', $orderId);

                    if (Schema::hasColumn('auto_invest_orders', 'order_id')) {
                        $query->orWhere('order_id', $orderId);
                    }
                });
            }

            $order = $orderQuery->first();

            if (!$order) {
                throw new Exception(__('Auto Invest order not found.'));
            }

            $investmentType = strtolower((string) ($order->investment_type ?? $order->type ?? 'fixed'));
            $isFlexible = $investmentType === 'flexible';

            $amount = $this->decimal($order->amount ?? 0);
            $usedMargin = $this->decimal($order->used_margin ?? 0);
            $principalAmount = $this->decimal($order->principal_amount ?? 0);
            $redeemedAmountBefore = $this->decimal($order->redeemed_amount ?? 0);

            if ($this->compare($amount, 0) <= 0) {
                throw new Exception(__('Redeemable amount is insufficient.'));
            }

            if ($this->compare($principalAmount, 0) <= 0) {
                $principalAmount = $amount;
            }

            $maturityDate = $this->getMaturityDate($order);
            $isMatured = $isFlexible || ($maturityDate && now()->greaterThanOrEqualTo($maturityDate));

            if (!$allowUnmaturedFixed && !$isFlexible && !$isMatured) {
                throw new Exception(__('Fixed Term orders can only be redeemed after maturity.'));
            }

            if ($this->compare($usedMargin, 0) <= 0) {
                $availableAmount = $amount;
                $remainingAmount = '0';
                $remainingUsedMargin = '0';
                $shouldCloseOrder = true;
            } else {
                $availableAmount = $this->sub($amount, $usedMargin);

                if ($this->compare($availableAmount, 0) <= 0) {
                    throw new Exception(__('This order is currently being used as trading margin and cannot be redeemed.'));
                }

                $remainingAmount = $usedMargin;
                $remainingUsedMargin = $usedMargin;
                $shouldCloseOrder = false;
            }

            $totalProfitAmount = $this->sub($amount, $principalAmount);
            $availableRatio = $this->compare($amount, 0) > 0
                ? $this->div($availableAmount, $amount)
                : '0';

            if ($this->compare($totalProfitAmount, 0) > 0) {
                $availableProfitAmount = $this->mul($totalProfitAmount, $availableRatio);
                $availablePrincipalAmount = $this->sub($availableAmount, $availableProfitAmount);
            } else {
                $availableProfitAmount = '0';
                $availablePrincipalAmount = $availableAmount;
            }

            $deductedProfitAmount = '0';

            if ($isFlexible && $this->compare($availableProfitAmount, 0) > 0) {
                $halfProfit = $this->div($availableProfitAmount, 2);
                $deductedProfitAmount = $halfProfit;
                $redeemAmount = $this->add($availablePrincipalAmount, $halfProfit);
            } else {
                $redeemAmount = $availableAmount;
            }

            if ($this->compare($redeemAmount, 0) <= 0) {
                throw new Exception(__('Redeemable amount is insufficient.'));
            }

            $wallet = DB::table('wallets')
                ->where('user_id', $user->id)
                ->where('currency_id', $order->currency_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new Exception(__('Wallet not found'));
            }

            $returnBalanceField = $this->getReturnBalanceField($order);

            DB::table('wallets')
                ->where('id', $wallet->id)
                ->update([
                    $returnBalanceField => DB::raw(
                        $returnBalanceField . ' + ' . $this->sqlDecimal($redeemAmount)
                    ),
                    'updated_at' => now(),
                ]);

            if (
                !$shouldCloseOrder &&
                $this->compare($principalAmount, 0) > 0 &&
                $this->compare($amount, 0) > 0
            ) {
                $remainingRatio = $this->div($remainingAmount, $amount);
                $newPrincipalAmount = $this->mul($principalAmount, $remainingRatio);
            } else {
                $newPrincipalAmount = '0';
            }

            $newRedeemedAmount = $this->add($redeemedAmountBefore, $redeemAmount);

            $update = [
                'amount' => $this->decimal($remainingAmount),
                'used_margin' => $this->decimal($remainingUsedMargin),
                'status' => $shouldCloseOrder ? 'closed' : 'active',
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('auto_invest_orders', 'principal_amount')) {
                $update['principal_amount'] = $this->decimal($newPrincipalAmount);
            }

            if (Schema::hasColumn('auto_invest_orders', 'redeemed_amount')) {
                $update['redeemed_amount'] = $this->decimal($newRedeemedAmount);
            }

            if (Schema::hasColumn('auto_invest_orders', 'deducted_profit_amount')) {
                $oldDeductedProfitAmount = $this->decimal($order->deducted_profit_amount ?? 0);

                $update['deducted_profit_amount'] = $this->decimal(
                    $this->add($oldDeductedProfitAmount, $deductedProfitAmount)
                );
            }

            $remainingProfitAmount = $this->sub($remainingAmount, $newPrincipalAmount);

            if (Schema::hasColumn('auto_invest_orders', 'settled_profit_amount')) {
                $update['settled_profit_amount'] = $this->decimal($remainingProfitAmount);
            }

            if (Schema::hasColumn('auto_invest_orders', 'total_profit')) {
                $update['total_profit'] = $this->decimal($remainingProfitAmount);
            }

            if (Schema::hasColumn('auto_invest_orders', 'current_total_profit_percent')) {
                if ($this->compare($newPrincipalAmount, 0) > 0) {
                    $profitPercent = $this->mul(
                        $this->div($remainingProfitAmount, $newPrincipalAmount),
                        100
                    );

                    $update['current_total_profit_percent'] = $this->decimal($profitPercent, 8);
                } else {
                    $update['current_total_profit_percent'] = 0;
                }
            }

            if ($shouldCloseOrder) {
                if (Schema::hasColumn('auto_invest_orders', 'redeemed_at')) {
                    $update['redeemed_at'] = now();
                }

                if (Schema::hasColumn('auto_invest_orders', 'closed_at')) {
                    $update['closed_at'] = now();
                }

                if (Schema::hasColumn('auto_invest_orders', 'ended_at')) {
                    $update['ended_at'] = now();
                }

                if (Schema::hasColumn('auto_invest_orders', 'last_settled_at')) {
                    $update['last_settled_at'] = now();
                }

                if (Schema::hasColumn('auto_invest_orders', 'close_reason')) {
                    $update['close_reason'] = $closeReason;
                }

                if (Schema::hasColumn('auto_invest_orders', 'end_reason')) {
                    $update['end_reason'] = $closeReason;
                }
            }

            DB::table('auto_invest_orders')
                ->where('id', $order->id)
                ->update($update);

            $this->syncUserAutoInvestState((int) $user->id);

            return [
                'redeem_amount' => $redeemAmount,
                'available_amount' => $availableAmount,
                'total_profit_amount' => $totalProfitAmount,
                'available_principal_amount' => $availablePrincipalAmount,
                'available_profit_amount' => $availableProfitAmount,
                'deducted_profit_amount' => $deductedProfitAmount,
                'order_remaining_amount' => $remainingAmount,
                'used_margin' => $remainingUsedMargin,
                'closed' => $shouldCloseOrder,
                'return_balance_field' => $returnBalanceField,
            ];
        });

        app(ReadModelCacheService::class)->invalidateWallets((int) $user->id);

        return $result;
    }

    protected function syncUserAutoInvestState(int $userId): void
    {
        $activeOrderQuery = DB::table('auto_invest_orders')
            ->where('user_id', $userId)
            ->where('status', 'active');

        if (Schema::hasColumn('auto_invest_orders', 'started_at')) {
            $activeOrderQuery->orderBy('started_at');
        }

        $activeOrder = $activeOrderQuery->orderBy('id')->first();

        if ($activeOrder) {
            $update = $this->buildUserAutoInvestStateUpdate([
                'auto_invest_funding' => 1,
                'invest_funding_time' => (int) ($activeOrder->days ?? 0),
                'auto_invest_type' => $activeOrder->investment_type ?? null,
                'auto_invest_amount' => DB::table('auto_invest_orders')
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->sum('amount'),
            ]);

            if (!$update) {
                return;
            }

            DB::table('users')
                ->where('id', $userId)
                ->update($update);

            return;
        }

        $update = $this->buildUserAutoInvestStateUpdate([
            'auto_invest_funding' => 0,
            'invest_funding_time' => 0,
            'auto_invest_type' => null,
            'auto_invest_amount' => 0,
        ]);

        if (!$update) {
            return;
        }

        DB::table('users')
            ->where('id', $userId)
            ->update($update);
    }

    protected function buildUserAutoInvestStateUpdate(array $values): array
    {
        $update = [];

        foreach ($values as $field => $value) {
            if (Schema::hasColumn('users', $field)) {
                $update[$field] = $value;
            }
        }

        if (Schema::hasColumn('users', 'updated_at')) {
            $update['updated_at'] = now();
        }

        return $update;
    }

    protected function getMaturityDate($order)
    {
        $dateValue = $order->maturity_date ?? $order->matured_at ?? null;

        if ($dateValue) {
            try {
                return \Carbon\Carbon::parse($dateValue);
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
            return \Carbon\Carbon::parse($startedAt)->addDays($days);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getReturnBalanceField($order): string
    {
        $sourceField = null;

        if (isset($order->source_balance_field)) {
            $sourceField = $order->source_balance_field;
        }

        if (!$sourceField && isset($order->source_field)) {
            $sourceField = $order->source_field;
        }

        $meta = [];

        if (!empty($order->meta)) {
            $decoded = json_decode((string) $order->meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if (!$sourceField && isset($meta['source_balance_field'])) {
            $sourceField = $meta['source_balance_field'];
        }

        if (!$sourceField && isset($meta['source_field'])) {
            $sourceField = $meta['source_field'];
        }

        if (!$sourceField && (isset($meta['source_account_type']) && $meta['source_account_type'] === 'virtual')) {
            $sourceField = 'balance_in_virtual_trade';
        }

        if (!$sourceField && isset($order->source_account_type) && $order->source_account_type === 'virtual') {
            $sourceField = 'balance_in_virtual_trade';
        }

        return $this->normalizeBalanceField($sourceField ?: 'balance_in_trade');
    }

    protected function normalizeBalanceField($field): string
    {
        $field = (string) $field;

        $allowedFields = [
            'balance_in_trade',
            'balance_in_virtual_trade',
        ];

        if (!in_array($field, $allowedFields, true)) {
            return 'balance_in_trade';
        }

        if (!Schema::hasColumn('wallets', $field)) {
            return 'balance_in_trade';
        }

        return $field;
    }

    protected function decimal($value, int $scale = 18): string { return \App\Services\Math\ExactDecimal::normalize($value,$scale); }
    protected function sqlDecimal($value, int $scale = 18): string { return $this->decimal($value,$scale); }
    protected function compare($left,$right): int { return bccomp($this->decimal($left),$this->decimal($right),18); }
    protected function add($left,$right): string { return bcadd($this->decimal($left),$this->decimal($right),18); }
    protected function sub($left,$right): string { return bcsub($this->decimal($left),$this->decimal($right),18); }
    protected function mul($left,$right): string { return bcmul($this->decimal($left),$this->decimal($right),18); }
    protected function div($left,$right): string {
        if ($this->compare($right,'0')===0) throw new \InvalidArgumentException('Cannot divide an amount by zero.');
        return bcdiv($this->decimal($left),$this->decimal($right),18);
    }
}
