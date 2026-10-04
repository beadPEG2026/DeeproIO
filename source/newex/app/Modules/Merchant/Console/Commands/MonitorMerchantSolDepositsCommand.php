<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Services\PaymentGateways\Coin\Solana\Services\SolanaService;
use Illuminate\Support\Facades\Log;

class MonitorMerchantSolDepositsCommand extends BaseMerchantDepositWatcher
{
    protected $signature = 'merchant:monitor-sol-deposits';
    protected $description = 'Monitor Solana SOL and SPL token deposits for merchant invoices';

    protected SolanaService $solanaService;

    public function __construct()
    {
        parent::__construct();
        $this->solanaService = new SolanaService();
    }

    public function handle(): int
    {
        // Check both SOL native and SPL tokens
        $addresses = $this->getActiveMerchantAddresses(NETWORK_SOL)
            ->merge($this->getActiveMerchantAddresses(NETWORK_SOL_SPL));

        if ($addresses->isEmpty()) {
            return 0;
        }

        $processed = 0;

        foreach ($addresses as $address) {
            $this->checkAddress($address);
            $processed++;
            usleep(200000); // Rate limiting - 200ms between requests
        }

        $this->logActivity('SOL/SPL', $addresses->count(), $processed);

        return 0;
    }

    protected function checkAddress($address): void
    {
        try {
            $isNativeSol = $address->network_id === NETWORK_SOL;

            if ($isNativeSol) {
                $this->checkSolTransfers($address);
            } else {
                $this->checkSplTransfers($address);
            }

        } catch (\Exception $e) {
            Log::error('Merchant SOL watcher exception', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkSolTransfers($address): void
    {
        try {

            $transfers = $this->solanaService->getSolTransfers($address->address, 20);

            foreach ($transfers as $transfer) {
                if (!isset($transfer['signature'])) {
                    continue;
                }

                // Only process incoming transfers
                if ($transfer['destinationWallet'] !== $address->address) {
                    continue;
                }

                $amount = $transfer['amount'];

                $this->processTransaction(
                    $address,
                    $transfer['signature'],
                    $amount,
                    2, // Solana finalized = confirmed
                    $transfer['sourceWallet'] ?? null,
                    $transfer['slot'] ?? null
                );
            }

        } catch (\Exception $e) {
            Log::error('Merchant SOL transfer check failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkSplTransfers($address): void
    {
        try {

            $currency = $address->currency;
            if (!$currency) {
                return;
            }

            $contract = $currency->sol_contract;
            if (!$contract) {
                return;
            }

            if(!$address->token_account) {

                $this->solanaService->getSolTransfers($address->address, 20, app(MerchantDepositAddress::class));

                if(!MerchantDepositAddress::where('id', $address->id)->whereNotNull('token_account')->first())
                    return;

                $address->refresh();
            }

            $transfers = $this->solanaService->getSplTransfers($address->address, $contract, $address->token_account, 20);

            foreach ($transfers as $transfer) {
                if (!isset($transfer['signature'])) {
                    continue;
                }

                // Only process incoming transfers
                if ($transfer['destinationWallet'] !== $address->address) {
                    continue;
                }

                $decimals = $transfer['decimals'] ?? 9;

                $amount = $transfer['amount'];
                //$amount = $this->formatTokenAmount($transfer['amount'] ?? '0', $decimals);

                $this->processTransaction(
                    $address,
                    $transfer['signature'],
                    $amount,
                    2, // Solana finalized = confirmed
                    $transfer['sourceWallet'] ?? null,
                    $transfer['slot'] ?? null
                );
            }

        } catch (\Exception $e) {
            Log::error('Merchant SPL transfer check failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function formatLamports(string $lamports): string
    {
        return bcdiv($lamports, '1000000000', 9); // 9 decimals for SOL
    }

    protected function formatTokenAmount(string $amount, int $decimals): string
    {
        return bcdiv($amount, bcpow('10', (string) $decimals), 18);
    }
}
