<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Services\PaymentGateways\Coin\Bitcoin\Services\CustomBitcoinService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorMerchantBtcDepositsCommand extends BaseMerchantDepositWatcher
{
    protected $signature = 'merchant:monitor-btc-deposits';
    protected $description = 'Monitor Bitcoin (BTC) deposits for merchant invoices';

    protected CustomBitcoinService $bitcoinService;

    public function __construct()
    {
        parent::__construct();
        $this->bitcoinService = new CustomBitcoinService();
    }

    public function handle(): int
    {
        $addresses = $this->getActiveMerchantAddresses(NETWORK_BTC);

        if ($addresses->isEmpty()) {
            return 0;
        }

        $processed = 0;

        // Get all recent transactions from the Bitcoin node
        try {
            $transactions = $this->bitcoinService->getTransactions();

            if (!is_array($transactions)) {
                Log::warning('Merchant BTC watcher: No transactions returned from node');
                return 0;
            }

            // Create a map of addresses for quick lookup
            $addressMap = [];
            foreach ($addresses as $address) {
                $addressMap[mb_strtolower($address->address)] = $address;
            }

            // Process transactions
            foreach ($transactions as $tx) {
                // Only process incoming transactions (category = receive)
                if (($tx['category'] ?? '') !== 'receive') {
                    continue;
                }

                $txAddress = mb_strtolower($tx['address'] ?? '');
                
                if (!isset($addressMap[$txAddress])) {
                    continue;
                }

                $address = $addressMap[$txAddress];
                $txnHash = $tx['txid'] ?? null;
                
                if (!$txnHash) {
                    continue;
                }

                $amount = (string) number_format((float) ($tx['amount'] ?? 0), 8, '.', '');
                $confirmations = (int) ($tx['confirmations'] ?? 0);

                $this->processTransaction(
                    $address,
                    $txnHash,
                    $amount,
                    $confirmations,
                    null, // Bitcoin doesn't easily expose sender address
                    isset($tx['blockheight']) ? (int) $tx['blockheight'] : null
                );

                $processed++;
            }

            $this->logActivity('BTC', $addresses->count(), $processed);

        } catch (\Exception $e) {
            Log::error('Merchant BTC watcher exception', [
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    /**
     * Alternative method: Check specific addresses using listreceivedbyaddress
     * This can be used if the wallet has many transactions
     */
    protected function checkAddressesViaListReceived($addresses): void
    {
        try {
            $receivedList = $this->getReceivedByAddress();

            if (!is_array($receivedList)) {
                return;
            }

            // Create a map for quick lookup
            $addressMap = [];
            foreach ($addresses as $address) {
                $addressMap[mb_strtolower($address->address)] = $address;
            }

            foreach ($receivedList as $received) {
                $addr = mb_strtolower($received['address'] ?? '');
                
                if (!isset($addressMap[$addr])) {
                    continue;
                }

                $address = $addressMap[$addr];
                $amount = (string) number_format((float) ($received['amount'] ?? 0), 8, '.', '');
                $confirmations = (int) ($received['confirmations'] ?? 0);

                // Get transactions for this address to get txids
                $txids = $received['txids'] ?? [];
                
                foreach ($txids as $txnHash) {
                    $this->processTransaction(
                        $address,
                        $txnHash,
                        $amount,
                        $confirmations,
                        null,
                        null
                    );
                }
            }

        } catch (\Exception $e) {
            Log::error('Merchant BTC listreceivedbyaddress check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get received amounts by address from Bitcoin node
     */
    protected function getReceivedByAddress(): array
    {
        $url = config('bitcoind.custom.url');
        $token = config('bitcoind.custom.token');
        $walletname = config('bitcoind.custom.walletname');

        $params = [
            'method' => 'listreceivedbyaddress',
            'params' => [0, true, true], // minconf=0, include_empty=true, include_watchonly=true
            'wallet' => $walletname
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url . '/api/wallet/request/node?token=' . $token, $params);

        $res = $response->json();

        return $res['data'] ?? [];
    }

    /**
     * Get transaction details by txid
     */
    protected function getTransactionDetails(string $txid): ?array
    {
        $url = config('bitcoind.custom.url');
        $token = config('bitcoind.custom.token');
        $walletname = config('bitcoind.custom.walletname');

        $params = [
            'method' => 'gettransaction',
            'params' => [$txid],
            'wallet' => $walletname
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url . '/api/wallet/request/node?token=' . $token, $params);

            $res = $response->json();

            return $res['data'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get BTC transaction details', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
