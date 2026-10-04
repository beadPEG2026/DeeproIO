<?php

namespace App\Services\Deposit;

use App\Models\Wallet\Wallet;
use App\Services\Wallet\WalletService;
use InvalidArgumentException;

/**
 * Credits the amount actually received to the user's wallet.
 *
 * The former promotional bonus is disabled; this service remains as the
 * single entry point so all deposit paths continue to use the same behavior.
 */
class DepositCreditService
{
    private WalletService $walletService;

    public function __construct(?WalletService $walletService = null)
    {
        $this->walletService = $walletService ?? new WalletService();
    }

    /**
     * Return the amount that should be credited to the user's wallet.
     */
    public function creditedAmount($baseAmount): string
    {
        if (!is_numeric($baseAmount)) {
            throw new InvalidArgumentException('Deposit amount must be numeric.');
        }

        return (string) $baseAmount;
    }

    /**
     * Credit a user's wallet and return the final amount credited.
     */
    public function credit(Wallet $wallet, $baseAmount): string
    {
        $creditedAmount = $this->creditedAmount($baseAmount);

        $this->walletService->increase($wallet, $creditedAmount);

        return $creditedAmount;
    }
}
