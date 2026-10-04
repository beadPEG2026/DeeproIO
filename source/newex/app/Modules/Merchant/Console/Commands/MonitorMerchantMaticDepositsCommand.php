<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Services\Blockchain\TokenDecimalsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorMerchantMaticDepositsCommand extends BaseMerchantDepositWatcher
{
    protected $signature = 'merchant:monitor-matic-deposits';
    protected $description = 'Monitor Polygon/MATIC deposits for merchant invoices';

    public function handle(): int
    {
        // Check both MATIC native and MATIC-20 tokens
        $addresses = $this->getActiveMerchantAddresses(NETWORK_MATIC)
            ->merge($this->getActiveMerchantAddresses(NETWORK_MATIC20));

        if ($addresses->isEmpty()) {
            return 0;
        }

        $processed = 0;

        foreach ($addresses as $address) {
            $this->checkAddress($address);
            $processed++;
            usleep(300000); // Rate limiting
        }

        $this->logActivity('MATIC', $addresses->count(), $processed);

        return 0;
    }

    protected function checkAddress($address): void
    {
        try {
            $currency = $address->currency;
            if (!$currency) {
                return;
            }

            // Check if it's a token or native MATIC
            $isToken = $address->network_id === NETWORK_MATIC20;
            $contract = $isToken ? $currency->matic_contract : null;

            $response = Http::get(env('APP_POLYGONSCAN_API', 'https://api.etherscan.io/v2/api'), [

            ]);

            $params = [
                'chainid' => 137,
                'module' => 'account',
                'action' => $isToken ? 'tokentx' : 'txlist',
                'address' => $address->address,
                'startblock' => '0',
                'endblock' => '999999999',
                'page' => '1',
                'offset' => '100',
                'sort' => 'desc',
                'apikey' => $this->getApiKey(),
            ];

            if ($isToken && $contract) {
                $params['contractaddress'] = $contract;
            }

            $response = Http::get(env('APP_POLYGONSCAN_API', 'https://api.etherscan.io/v2/api'), $params);

            $data = $response->json();

            if ($response->successful() && isset($data['status']) && $data['status'] == '1') {
                foreach ($data['result'] as $tx) {
                    if (!isset($tx['hash']) || !$tx['hash']) {
                        continue;
                    }

                    // Only process incoming transactions
                    if (mb_strtolower($tx['to']) !== mb_strtolower($address->address)) {
                        continue;
                    }

                    $decimals = $isToken
                        ? (new TokenDecimalsService())->resolve(NETWORK_MATIC20, $contract, $tx['tokenDecimal'] ?? null, (int) ($currency->decimals ?? 18))
                        : 18;
                    $amount = $this->formatAmount($tx['value'], $decimals);
                    $confirmations = (int) ($tx['confirmations'] ?? 0);

                    $this->processTransaction(
                        $address,
                        $tx['hash'],
                        $amount,
                        $confirmations,
                        $tx['from'] ?? null,
                        isset($tx['blockNumber']) ? (int) $tx['blockNumber'] : null
                    );
                }
            }

            if (!$response->successful()) {
                Log::error('Merchant MATIC watcher API error', [
                    'address' => $address->address,
                    'response' => $response->body(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Merchant MATIC watcher exception', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function formatAmount(string $value, int $decimals): string
    {
        return bcdiv($value, bcpow('10', (string) $decimals), 18);
    }

    protected function getApiKey(): string
    {
        return env('APP_POLYGONSCAN_KEY', '');
    }
}
