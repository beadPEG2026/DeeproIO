<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Services\Blockchain\TokenDecimalsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorMerchantTrcDepositsCommand extends BaseMerchantDepositWatcher
{
    protected $signature = 'merchant:monitor-trc-deposits';
    protected $description = 'Monitor TRX and TRC-20 deposits for merchant invoices';

    public function handle(): int
    {
        // Check both TRX native and TRC-20 tokens
        $addresses = $this->getActiveMerchantAddresses(NETWORK_TRX)
            ->merge($this->getActiveMerchantAddresses(NETWORK_TRC));

        if ($addresses->isEmpty()) {
            return 0;
        }

        $processed = 0;

        foreach ($addresses as $address) {
            $this->checkAddress($address);
            $processed++;
            usleep(300000); // Rate limiting
        }

        $this->logActivity('TRX/TRC-20', $addresses->count(), $processed);

        return 0;
    }

    protected function checkAddress($address): void
    {
        try {
            // Check if it's a token or native TRX
            $isToken = $address->network_id === NETWORK_TRC;

            if ($isToken) {
                $this->checkTrcTransfers($address);
            } else {
                $this->checkTrxTransfers($address);
            }

        } catch (\Exception $e) {
            Log::error('Merchant TRX/TRC watcher exception', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkTrxTransfers($address): void
    {
        try {
            // Use Tronscan API for native TRX transfers
            $response = Http::get(env('APP_TRONSCAN_API', 'https://apilist.tronscan.org') . '/api/transaction', [
                'address' => $address->address,
                'limit' => '50',
                'start' => '0',
                'sort' => '-timestamp',
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['data'])) {
                foreach ($data['data'] as $tx) {
                    if (!isset($tx['hash']) || !$tx['hash']) {
                        continue;
                    }

                    // Only process TRX transfers (contractType 1 = TransferContract)
                    if (($tx['contractType'] ?? 0) != 1) {
                        continue;
                    }

                    // Only process incoming transactions
                    if (($tx['toAddress'] ?? '') !== $address->address || !($tx['confirmed'] ?? false)) {
                        continue;
                    }

                    // TRX uses 6 decimals (SUN)
                    $amount = $this->formatAmount((string) ($tx['amount'] ?? '0'), 6);
                    $confirmations = ($tx['confirmed'] ?? false) ? 10 : 0;

                    $this->processTransaction(
                        $address,
                        $tx['hash'],
                        $amount,
                        $confirmations,
                        $tx['ownerAddress'] ?? null,
                        isset($tx['block']) ? (int) $tx['block'] : null
                    );
                }
            }

            if (!$response->successful()) {
                Log::error('Merchant TRX watcher API error', [
                    'address' => $address->address,
                    'response' => $response->body(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Merchant TRX transfer check failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkTrcTransfers($address): void
    {
        try {
            $response = Http::get(env('APP_TRONSCAN_API', 'https://apilist.tronscan.org') . '/api/contract/events', [
                'count' => 'true',
                'limit' => '30',
                'address' => $address->address,
                'start' => '0',
                'sort' => '-timestamp',
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['total']) && $data['total'] >= 0) {
                foreach ($data['data'] as $tx) {

                    if (!isset($tx['transactionHash']) || !$tx['transactionHash']) {
                        continue;
                    }

                    // Only process incoming transactions
                    if ($tx['transferToAddress'] !== $address->address || !$tx['confirmed']) {
                        continue;
                    }

                    $currency = $address->currency;
                    $decimals = (new TokenDecimalsService())->resolve(
                        NETWORK_TRC,
                        $tx['contract'] ?? $tx['contractAddress'] ?? $currency->trc_contract ?? null,
                        $tx['decimals'] ?? null,
                        (int) ($currency->decimals ?? 6)
                    );
                    $amount = $this->formatAmount($tx['amount'] ?? '0', $decimals);
                    $confirmations = 10; // TRC-20 is typically confirmed quickly

                    $this->processTransaction(
                        $address,
                        $tx['transactionHash'],
                        $amount,
                        $confirmations,
                        $tx['transferFromAddress'] ?? null,
                        isset($tx['block']) ? (int) $tx['block'] : null
                    );
                }
            }

            if (!$response->successful()) {
                Log::error('Merchant TRC watcher API error', [
                    'address' => $address->address,
                    'response' => $response->body(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Merchant TRC transfer check failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function formatAmount(string $value, int $decimals): string
    {
        return bcdiv($value, bcpow('10', (string) $decimals), 18);
    }
}
