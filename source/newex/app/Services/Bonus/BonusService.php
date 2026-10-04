<?php

namespace App\Services\Bonus;

use App\Models\User\User;
use App\Repositories\Bonus\DepositBonusRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Setting;

class BonusService
{
    protected DepositBonusRepository $repo;
    protected WalletRepository $walletRepo;
    protected WalletService $walletService;

    public function __construct()
    {
        $this->repo = new DepositBonusRepository();
        $this->walletRepo = new WalletRepository();
        $this->walletService = new WalletService();
    }

    /**
     * Award deposit and referral bonuses for a confirmed deposit.
     *
     * @param int $depositorId The user who made the deposit
     * @param int $currencyId Currency ID of the deposit
     * @param string $amountCredited The amount actually credited to wallet (after fees)
     * @param string|null $depositId Unique deposit_id for idempotency
     */
    public function awardForDeposit(int $depositorId, int $currencyId, string $amountCredited, ?string $depositId = null): void
    {
        // Configurable percents from admin settings
        $selfPercent = (string) Setting::get('bonus.deposit_percent', 8);
        $refPercent = (string) Setting::get('bonus.referral_percent', 12);

        if (math_compare($selfPercent, 0) > 0 && math_compare($amountCredited, 0) > 0) {
            $this->awardOne(
                $depositorId,
                null,
                $currencyId,
                $amountCredited,
                $selfPercent,
                'self',
                $depositId
            );
        }

        // Referral bonus
        $depositor = User::find($depositorId);
        if ($depositor && $depositor->referral_id && math_compare($refPercent, 0) > 0 && math_compare($amountCredited, 0) > 0) {
            $this->awardOne(
                (int) $depositor->referral_id,
                $depositorId,
                $currencyId,
                $amountCredited,
                $refPercent,
                'referral',
                $depositId
            );
        }
    }

    protected function awardOne(int $recipientId, ?int $sourceUserId, int $currencyId, string $baseAmount, string $percent, string $type, ?string $depositId): void
    {
        // Idempotency check (per depositId + user + type)
        if ($depositId) {
            $existing = $this->repo->findExisting($depositId, $recipientId, $type);
            if ($existing) {
                return;
            }
        }

        $bonusAmount = math_percentage($baseAmount, $percent);
        if (math_compare($bonusAmount, 0) <= 0) {
            return;
        }

        // Credit recipient wallet in same currency
        $wallet = $this->walletRepo->getWalletByCurrency($recipientId, $currencyId);
        if (!$wallet) {
            return; // no wallet for some reason
        }
        $this->walletService->increase($wallet, $bonusAmount);

        // Store bonus record
        $this->repo->store([
            'user_id' => $recipientId,
            'source_user_id' => $sourceUserId,
            'deposit_id' => $depositId,
            'currency_id' => $currencyId,
            'amount_deposited' => $baseAmount,
            'percent' => $percent,
            'bonus_amount' => $bonusAmount,
            'type' => $type,
        ]);
    }
}
