<?php

namespace App\Services\Withdrawal;

use InvalidArgumentException;

/**
 * Calculates the fee charged for user withdrawals.
 *
 * Currency records store the original percentage and fixed withdrawal fees.
 * This service centralizes the original currency-based calculation for every
 * withdrawal entry point.
 */
class WithdrawalFeeService
{
    /** Calculate the configured fee for a cryptocurrency withdrawal network. */
    public function calculateCryptoFee($currency, $amount, ?string $networkSlug = null): string
    {
        if (strtolower(trim((string) $networkSlug)) === 'internal') {
            return '0';
        }

        $suffix = match (strtolower(trim((string) $networkSlug))) {
            'erc20' => 'erc',
            'bep20' => 'bep',
            'trc20' => 'trc',
            'matic20' => 'matic',
            'xlayer20' => 'xlayer',
            'solspl' => 'sol',
            default => null,
        };

        $percentField = $suffix ? 'withdraw_fee_' . $suffix : 'withdraw_fee';
        $fixedField = $suffix ? 'withdraw_fee_' . $suffix . '_fixed' : 'withdraw_fee_fixed';

        return $this->calculateConfiguredFee(
            $amount,
            $currency->{$percentField} ?? 0,
            $currency->{$fixedField} ?? 0
        );
    }

    /** Calculate the configured fee for a fiat withdrawal. */
    public function calculateFiatFee($currency, $amount): string
    {
        return $this->calculateConfiguredFee(
            $amount,
            $currency->withdraw_fee ?? 0,
            $currency->withdraw_fee_fixed ?? 0
        );
    }

    /**
     * Compatibility helper for callers that used the previous promotion
     * wrapper. Restoring the original rule means the value is unchanged.
     */
    public function applyIncrease($baseFee): string
    {
        return $this->numericString($baseFee, 'Withdrawal fee');
    }

    private function calculateConfiguredFee($amount, $percent, $fixed): string
    {
        $amount = $this->numericString($amount, 'Withdrawal amount');
        $percent = $this->numericString($percent, 'Withdrawal fee percent');
        $fixed = $this->numericString($fixed, 'Fixed withdrawal fee');

        if (math_compare($amount, '0') <= 0) {
            return '0';
        }

        if (math_compare($percent, '0') > 0) {
            return math_percentage($amount, $percent);
        }

        return math_compare($fixed, '0') > 0 ? $fixed : '0';
    }

    private function numericString($value, string $label): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException($label . ' must be numeric.');
        }

        return (string) $value;
    }
}
