<?php

namespace App\Modules\Merchant\Services;

use App\Helpers\PaymentGateways\Bnb\BnbNodeHelper;
use App\Helpers\PaymentGateways\Ethereum\EthereumNodeHelper;
use App\Helpers\PaymentGateways\Polygon\PolygonNodeHelper;
use App\Helpers\PaymentGateways\Solana\SolanaNodeHelper;
use App\Helpers\PaymentGateways\Tron\TronNodeHelper;
use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FundCollectionService
{
    /**
     * Get the hot wallet address for a network
     */
    public function getHotWalletAddress(int $networkId): ?string
    {
        $network = Network::find($networkId);
        if (!$network) {
            return null;
        }

        $networkSlug = strtolower($network->slug);

        // Map network slugs to settings keys
        $settingsMap = [
            'erc' => 'ethereum',
            'erc20' => 'ethereum',
            'eth' => 'ethereum',
            'trc' => 'tron',
            'trc20' => 'tron',
            'trx' => 'tron',
            'bep' => 'bnb',
            'bep20' => 'bnb',
            'bsc' => 'bnb',
            'bnb' => 'bnb',
            'polygon' => 'polygon',
            'matic' => 'polygon',
            'matic20' => 'polygon',
            'sol' => 'solana',
            'solana' => 'solana',
            'solspl' => 'solana',
            'ton' => 'ton',
            'xrp' => 'ripple',
            'ripple' => 'ripple',
            'btc' => 'bitcoin',
            'bitcoin' => 'bitcoin',
        ];

        $settingsKey = $settingsMap[$networkSlug] ?? $networkSlug;

        return setting("{$settingsKey}.wallet");
    }

    /**
     * Get the hot wallet private key for a network
     */
    public function getHotWalletPrivateKey(int $networkId): ?string
    {

        $network = Network::find($networkId);
        if (!$network) {
            return null;
        }

        $networkSlug = strtolower($network->slug);

        $settingsMap = [
            'erc' => 'ethereum',
            'erc20' => 'ethereum',
            'eth' => 'ethereum',
            'trc' => 'tron',
            'trc20' => 'tron',
            'trx' => 'tron',
            'bep' => 'bnb',
            'bep20' => 'bnb',
            'bsc' => 'bnb',
            'bnb' => 'bnb',
            'polygon' => 'polygon',
            'matic' => 'polygon',
            'matic20' => 'polygon',
            'sol' => 'solana',
            'solana' => 'solana',
            'solspl' => 'solana',
            'ton' => 'ton',
            'xrp' => 'ripple',
            'ripple' => 'ripple',
            'btc' => 'bitcoin',
            'bitcoin' => 'bitcoin',
        ];

        $settingsKey = $settingsMap[$networkSlug] ?? $networkSlug;

        return setting("{$settingsKey}.private_key");
    }

    /**
     * Get addresses that need fund collection (with lock for update)
     */
    public function getAddressesForCollection(int $networkId): \Illuminate\Database\Eloquent\Collection
    {
        return MerchantDepositAddress::where('network_id', $networkId)
            ->where('is_sweep_required', true)
            ->whereNull('swept_at')
            ->whereNotNull('private_key')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Get total balance of an address for collection (with lock to prevent double collection)
     */
    public function getAddressBalance(MerchantDepositAddress $address): array
    {
        // Lock payments to prevent concurrent collection
        $payments = MerchantInvoicePayment::where('deposit_address_id', $address->id)
            ->where('status', 'confirmed')
            ->whereNull('collected_at')
            ->lockForUpdate()
            ->get();

        $balances = [];
        foreach ($payments as $payment) {
            $currencyId = $payment->invoice->currency_id ?? null;
            if ($currencyId) {
                if (!isset($balances[$currencyId])) {
                    $balances[$currencyId] = [
                        'currency' => Currency::find($currencyId),
                        'amount' => '0',
                        'payments' => [],
                    ];
                }
                $balances[$currencyId]['amount'] = math_sum($balances[$currencyId]['amount'], (string) $payment->amount_crypto);
                $balances[$currencyId]['payments'][] = $payment;
            }
        }

        return $balances;
    }

    /**
     * Safely collect funds with transaction and locking
     */
    public function collectFundsSafely(MerchantDepositAddress $address, callable $collectFunction): array
    {
        return DB::transaction(function () use ($address, $collectFunction) {
            // Lock the address to prevent concurrent collection
            $lockedAddress = MerchantDepositAddress::where('id', $address->id)
                ->where('is_sweep_required', true)
                ->whereNull('swept_at')
                ->lockForUpdate()
                ->first();

            if (!$lockedAddress) {
                return ['success' => false, 'error' => 'Address already swept or not eligible'];
            }

            // Get balance with locked payments
            $balances = $this->getAddressBalance($lockedAddress);

            if (empty($balances)) {
                return ['success' => false, 'error' => 'No uncollected payments found'];
            }

            // Execute the collection function
            $result = $collectFunction($lockedAddress, $balances);

            if ($result['success'] && !empty($result['txn_hash'])) {
                $this->markAsSwept($lockedAddress, $result['txn_hash']);
            }

            return $result;
        }, 5);
    }

    /**
     * Collect ERC-20 tokens to hot wallet
     */
    public function collectErcFunds(MerchantDepositAddress $address, Currency $currency, string $amount, $network): array
    {
        $hotWallet = $this->getHotWalletAddress($address->network_id);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($address->network_id);

        if (!$hotWallet || !$hotWalletPrivateKey) {
            return ['success' => false, 'error' => 'Hot wallet not configured'];
        }

        $route = 'wallet.merchant.sweep.erc';

        if($network == "eth")
            $route = 'wallet.merchant.sweep.eth';


        try {
            $response = Http::timeout(60)->post(EthereumNodeHelper::route($route), [
                'id' => $address->id,
                'deposit_id' => $address->id,
                'address' => $address->address,
                'address_private_key' => $address->private_key,
                'amount' => $amount,
                'wei' => $this->toWei($amount, $currency->decimals ?? 18),
                'wallet' => $hotWallet,
                'private_key' => $hotWalletPrivateKey,
                'fee' => true,
                'contract' => $currency->contract
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('ERC fund collection failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Collect TRC-20 tokens to hot wallet
     */
    public function collectTrcFunds(MerchantDepositAddress $address, Currency $currency, string $amount, $network): array
    {
        $hotWallet = $this->getHotWalletAddress($address->network_id);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($address->network_id);

        if (!$hotWallet || !$hotWalletPrivateKey) {
            return ['success' => false, 'error' => 'Hot wallet not configured'];
        }

        $route = 'wallet.merchant.sweep.trc';

        if($network == "trx")
            $route = 'wallet.merchant.sweep.trx';

        try {
            $response = Http::timeout(60)->post(TronNodeHelper::route($route), [
                'id' => $address->id,
                'deposit_id' => $address->id,
                'address' => $address->address,
                'address_private_key' => $address->private_key,
                'amount' => $amount,
                'wei' => $this->toWei($amount, $currency->decimals ?? 6),
                'wallet' => $hotWallet,
                'private_key' => $hotWalletPrivateKey,
                'contract' => $currency->trc_contract,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('TRC fund collection failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Collect BEP-20 tokens to hot wallet
     */
    public function collectBepFunds(MerchantDepositAddress $address, Currency $currency, string $amount, $network): array
    {
        $hotWallet = $this->getHotWalletAddress($address->network_id);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($address->network_id);

        if (!$hotWallet || !$hotWalletPrivateKey) {
            return ['success' => false, 'error' => 'Hot wallet not configured'];
        }

        $route = 'wallet.merchant.sweep.bep';

        if($network == "bnb")
            $route = 'wallet.merchant.sweep.bnb';


        try {
            $response = Http::timeout(60)->post(BnbNodeHelper::route($route), [
                'id' => $address->id,
                'deposit_id' => $address->id,
                'address' => $address->address,
                'address_private_key' => $address->private_key,
                'amount' => $amount,
                'wei' => $this->toWei($amount, $currency->decimals ?? 18),
                'wallet' => $hotWallet,
                'private_key' => $hotWalletPrivateKey,
                'fee' => true,
                'contract' => $currency->bep_contract,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('BEP fund collection failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Collect Polygon/MATIC tokens to hot wallet
     */
    public function collectPolygonFunds(MerchantDepositAddress $address, Currency $currency, string $amount, $network): array
    {
        $hotWallet = $this->getHotWalletAddress($address->network_id);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($address->network_id);

        if (!$hotWallet || !$hotWalletPrivateKey) {
            return ['success' => false, 'error' => 'Hot wallet not configured'];
        }

        $route = 'wallet.merchant.sweep.matic20';

        if($network == "matic")
            $route = 'wallet.merchant.sweep.matic';


        try {
            $response = Http::timeout(60)->post(PolygonNodeHelper::route($route), [
                'id' => $address->id,
                'deposit_id' => $address->id,
                'address' => $address->address,
                'address_private_key' => $address->private_key,
                'amount' => $amount,
                'wei' => $this->toWei($amount, $currency->decimals ?? 18),
                'wallet' => $hotWallet,
                'private_key' => $hotWalletPrivateKey,
                'fee' => true,
                'contract' => $currency->matic_contract,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('Polygon fund collection failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Collect Solana/SPL tokens to hot wallet
     */
    public function collectSolanaFunds(MerchantDepositAddress $address, Currency $currency, string $amount, bool $isSpl = false): array
    {
        $hotWallet = $this->getHotWalletAddress($address->network_id);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($address->network_id);

        if (!$hotWallet || !$hotWalletPrivateKey) {
            return ['success' => false, 'error' => 'Hot wallet not configured'];
        }

        try {
            $route = $isSpl ? 'wallet.merchant.sweep.spl' : 'wallet.merchant.sweep.sol';

            $params = [
                'id' => $address->id,
                'deposit_id' => $address->id,
                'address' => $address->address,
                'address_private_key' => $address->private_key,
                'amount' => $amount,
                'wallet' => $hotWallet,
                'private_key' => (new SolanaGateway())->jsonToHex($hotWalletPrivateKey),
            ];

            if ($isSpl && $currency->sol_contract) {
                $params['contract'] = $currency->sol_contract;
            }

            $response = Http::timeout(60)->post(SolanaNodeHelper::route($route), $params);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('Solana fund collection failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Collect Bitcoin to hot wallet
     * Note: Bitcoin addresses are managed by the wallet, so funds are already accessible.
     * This method just marks payments as collected.
     */
    public function collectBitcoinFunds(MerchantDepositAddress $address, Currency $currency, string $amount): array
    {
        // For Bitcoin, the wallet already has access to funds from all generated addresses
        // We just need to mark the payments as collected
        try {
            Log::info('Bitcoin funds collected (wallet-managed)', [
                'address' => $address->address,
                'amount' => $amount,
                'currency' => $currency->symbol,
            ]);

            // Generate a collection ID since Bitcoin wallet manages all addresses
            $collectionId = 'btc-collect-' . $address->id . '-' . time();

            return [
                'success' => true,
                'txn_hash' => $collectionId,
                'data' => [
                    'message' => 'Bitcoin funds are wallet-managed and accessible',
                    'address' => $address->address,
                    'amount' => $amount,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Bitcoin fund collection failed', [
                'address' => $address->address,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Mark address as swept
     */
    public function markAsSwept(MerchantDepositAddress $address, string $txHash): void
    {
        $address->recordSweep($txHash);

        if($txHash) {
            // Mark all payments as collected
            MerchantInvoicePayment::where('deposit_address_id', $address->id)
                ->whereNull('collected_at')
                ->update([
                    'collected_at' => now(),
                    'collection_txn_hash' => $txHash,
                ]);
        }
    }

    /**
     * Convert amount to wei/lamports based on decimals
     */
    protected function toWei(string $amount, int $decimals): string
    {
        return bcmul($amount, bcpow('10', (string) $decimals, 0), 0);
    }
}
