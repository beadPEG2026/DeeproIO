<?php

namespace App\Modules\Merchant\Services;

use App\Helpers\PaymentGateways\Bnb\BnbNodeHelper;
use App\Helpers\PaymentGateways\Ethereum\EthereumNodeHelper;
use App\Helpers\PaymentGateways\Polygon\PolygonNodeHelper;
use App\Helpers\PaymentGateways\Solana\SolanaNodeHelper;
use App\Helpers\PaymentGateways\Tron\TronNodeHelper;
use App\Models\Currency\Currency;
use App\Models\Network\Network;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantPayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayoutTransferService
{
    /**
     * Network slug to settings key mapping
     */
    protected array $networkSettingsMap = [
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
        'spl' => 'solana',
    ];

    protected MerchantNotificationService $notificationService;

    public function __construct(MerchantNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Process and send a payout
     */
    public function processAndSendPayout(MerchantPayout $payout): array
    {
        Log::info('Starting payout transfer', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'amount_usd' => $payout->net_amount_usd,
        ]);

        // Validate payout has required fields
        if (!$payout->network_id || !$payout->currency_id) {
            return $this->handleFailure($payout, 'MISSING_NETWORK_CURRENCY', 'Network and currency must be selected for payout');
        }

        $network = Network::find($payout->network_id);
        $currency = Currency::find($payout->currency_id);

        if (!$network || !$currency) {
            return $this->handleFailure($payout, 'INVALID_NETWORK_CURRENCY', 'Invalid network or currency');
        }

        // Get hot wallet configuration
        $hotWalletAddress = $this->getHotWalletAddress($network);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($network);

        if (!$hotWalletAddress || !$hotWalletPrivateKey) {
            return $this->handleFailure($payout, 'HOT_WALLET_NOT_CONFIGURED', 'Hot wallet not configured for network: ' . $network->name);
        }

        // Calculate crypto amount from USD
        $cryptoAmount = $this->calculateCryptoAmount($payout, $currency);
        if (!$cryptoAmount || $cryptoAmount <= 0) {
            return $this->handleFailure($payout, 'INVALID_CRYPTO_AMOUNT', 'Could not calculate crypto amount');
        }

        // Update payout with crypto amount and rate
        $payout->update([
            'amount_crypto' => $cryptoAmount,
            'rate_usd' => $currency->rate ?? 0,
        ]);

        // Mark as processing
        $payout->markProcessing();

        // Send the funds based on network type
        $result = $this->sendFunds(
            $network,
            $currency,
            $hotWalletAddress,
            $hotWalletPrivateKey,
            $payout->payout_address,
            $cryptoAmount
        );

        if ($result['success']) {
            return $this->handleSuccess($payout, $result, $network);
        } else {
            return $this->handleFailure($payout, $result['error_code'] ?? 'TRANSFER_FAILED', $result['error'] ?? 'Unknown error');
        }
    }

    /**
     * Send funds based on network type
     */
    protected function sendFunds(
        Network $network,
        Currency $currency,
        string $fromAddress,
        string $privateKey,
        string $toAddress,
        string $amount
    ): array {
        $networkSlug = strtolower($network->slug);
        $decimals = $currency->decimals ?? 18;
        $wei = $this->toWei($amount, $decimals);

        try {
            // Determine which bridge to use based on network
            if (in_array($networkSlug, ['erc', 'erc20', 'eth'])) {
                return $this->sendErcFunds($fromAddress, $privateKey, $toAddress, $amount, $wei, $currency);
            } elseif (in_array($networkSlug, ['trc', 'trc20', 'trx'])) {
                return $this->sendTrcFunds($fromAddress, $privateKey, $toAddress, $amount, $wei, $currency);
            } elseif (in_array($networkSlug, ['bep', 'bep20', 'bsc', 'bnb'])) {
                return $this->sendBepFunds($fromAddress, $privateKey, $toAddress, $amount, $wei, $currency);
            } elseif (in_array($networkSlug, ['polygon', 'matic', 'matic20'])) {
                return $this->sendPolygonFunds($fromAddress, $privateKey, $toAddress, $amount, $wei, $currency);
            } elseif (in_array($networkSlug, ['sol', 'solana', 'spl'])) {
                return $this->sendSolanaFunds($fromAddress, $privateKey, $toAddress, $amount, $currency);
            } else {
                return ['success' => false, 'error' => 'Unsupported network: ' . $network->name, 'error_code' => 'UNSUPPORTED_NETWORK'];
            }
        } catch (\Exception $e) {
            Log::error('Payout transfer exception', [
                'network' => $networkSlug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'error' => $e->getMessage(), 'error_code' => 'EXCEPTION'];
        }
    }

    /**
     * Send ERC-20/ETH funds
     */
    protected function sendErcFunds(string $fromAddress, string $privateKey, string $toAddress, string $amount, string $wei, Currency $currency): array
    {
        $isNative = empty($currency->contract) || strtolower($currency->symbol) === 'eth';
        $route = $isNative ? 'wallet.transfer.eth' : 'wallet.transfer.erc';

        $params = [
            'wallet' => $fromAddress,
            'private_key' => $privateKey,
            'address' => $toAddress,
            'amount' => $amount,
            'wei' => $wei,
        ];

        if (!$isNative) {
            $params['contract'] = $currency->contract;
        }

        $response = Http::timeout(120)->post(EthereumNodeHelper::route($route), $params);

        return $this->parseResponse($response, 'ERC');
    }

    /**
     * Send TRC-20/TRX funds
     */
    protected function sendTrcFunds(string $fromAddress, string $privateKey, string $toAddress, string $amount, string $wei, Currency $currency): array
    {
        $isNative = empty($currency->contract) || strtolower($currency->symbol) === 'trx';
        $route = $isNative ? 'wallet.transfer.trx' : 'wallet.transfer.trc';

        $params = [
            'wallet' => $fromAddress,
            'private_key' => $privateKey,
            'address' => $toAddress,
            'amount' => $amount,
            'wei' => $wei,
        ];

        if (!$isNative) {
            $params['contract'] = $currency->contract;
        }

        $response = Http::timeout(120)->post(TronNodeHelper::route($route), $params);

        return $this->parseResponse($response, 'TRC');
    }

    /**
     * Send BEP-20/BNB funds
     */
    protected function sendBepFunds(string $fromAddress, string $privateKey, string $toAddress, string $amount, string $wei, Currency $currency): array
    {
        $isNative = empty($currency->contract) || strtolower($currency->symbol) === 'bnb';
        $route = $isNative ? 'wallet.transfer.bnb' : 'wallet.transfer.bep';

        $params = [
            'wallet' => $fromAddress,
            'private_key' => $privateKey,
            'address' => $toAddress,
            'amount' => $amount,
            'wei' => $wei,
        ];

        if (!$isNative) {
            $params['contract'] = $currency->contract;
        }

        $response = Http::timeout(120)->post(BnbNodeHelper::route($route), $params);

        return $this->parseResponse($response, 'BEP');
    }

    /**
     * Send Polygon/MATIC funds
     */
    protected function sendPolygonFunds(string $fromAddress, string $privateKey, string $toAddress, string $amount, string $wei, Currency $currency): array
    {
        $isNative = empty($currency->contract) || strtolower($currency->symbol) === 'matic';
        $route = $isNative ? 'wallet.transfer.matic' : 'wallet.transfer.matic20';

        $params = [
            'wallet' => $fromAddress,
            'private_key' => $privateKey,
            'address' => $toAddress,
            'amount' => $amount,
            'wei' => $wei,
        ];

        if (!$isNative) {
            $params['contract'] = $currency->contract;
        }

        $response = Http::timeout(120)->post(PolygonNodeHelper::route($route), $params);

        return $this->parseResponse($response, 'POLYGON');
    }

    /**
     * Send Solana/SPL funds
     */
    protected function sendSolanaFunds(string $fromAddress, string $privateKey, string $toAddress, string $amount, Currency $currency): array
    {
        $isSpl = !empty($currency->contract) && strtolower($currency->symbol) !== 'sol';
        $route = $isSpl ? 'wallet.transfer.spl' : 'wallet.transfer';

        $params = [
            'wallet' => $fromAddress,
            'private_key' => $privateKey,
            'address' => $toAddress,
            'amount' => $amount,
        ];

        if ($isSpl) {
            $params['contract'] = $currency->contract;
        }

        $response = Http::timeout(120)->post(SolanaNodeHelper::route($route), $params);

        return $this->parseResponse($response, 'SOLANA');
    }

    /**
     * Parse bridge response
     */
    protected function parseResponse($response, string $networkType): array
    {
        Log::info("Payout {$networkType} bridge response", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            return [
                'success' => false,
                'error' => "Bridge request failed with status {$response->status()}: " . $response->body(),
                'error_code' => 'BRIDGE_HTTP_ERROR',
            ];
        }

        $data = $response->json();

        // Check for various success indicators
        if (isset($data['success']) && $data['success'] === false) {
            return [
                'success' => false,
                'error' => $data['message'] ?? $data['error'] ?? 'Bridge returned failure',
                'error_code' => $data['code'] ?? 'BRIDGE_FAILURE',
            ];
        }

        // Look for transaction hash in various formats
        $txHash = $data['txHash'] ?? $data['tx_hash'] ?? $data['hash'] ?? $data['transactionHash'] ?? $data['txid'] ?? null;

        if (!$txHash && isset($data['data'])) {
            $txHash = $data['data']['txHash'] ?? $data['data']['tx_hash'] ?? $data['data']['hash'] ?? null;
        }

        if ($txHash) {
            return [
                'success' => true,
                'txn_hash' => $txHash,
                'data' => $data,
            ];
        }

        // If we got here, check if there's an explicit error
        if (isset($data['error']) || isset($data['message'])) {
            return [
                'success' => false,
                'error' => $data['error'] ?? $data['message'],
                'error_code' => $data['code'] ?? 'BRIDGE_ERROR',
            ];
        }

        // No clear success or failure - log and treat as failure
        return [
            'success' => false,
            'error' => 'Could not determine transaction result from bridge response',
            'error_code' => 'UNKNOWN_RESPONSE',
        ];
    }

    /**
     * Handle successful transfer
     */
    protected function handleSuccess(MerchantPayout $payout, array $result, Network $network): array
    {
        $txnHash = $result['txn_hash'];
        $explorerUrl = $this->buildExplorerUrl($network, $txnHash);

        $payout->complete($txnHash, $explorerUrl);

        Log::info('Payout transfer successful', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'txn_hash' => $txnHash,
        ]);

        // Notify merchant of successful payout
        $this->notificationService->notifyPayoutCompleted($payout->fresh());

        return [
            'success' => true,
            'txn_hash' => $txnHash,
            'explorer_url' => $explorerUrl,
            'payout' => $payout->fresh(),
        ];
    }

    /**
     * Handle failed transfer
     */
    protected function handleFailure(MerchantPayout $payout, string $errorCode, string $errorMessage): array
    {
        DB::transaction(function () use ($payout, $errorCode, $errorMessage) {
            // Lock the payout and merchant to prevent race conditions
            $lockedPayout = MerchantPayout::where('id', $payout->id)->lockForUpdate()->first();
            $lockedMerchant = Merchant::where('id', $payout->merchant_id)->lockForUpdate()->first();
            
            if (!$lockedPayout || !$lockedMerchant) {
                throw new \RuntimeException('Payout or merchant not found');
            }

            $lockedPayout->update([
                'status' => MerchantPayout::STATUS_FAILED,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'failed_at' => now(),
                'retry_count' => $lockedPayout->retry_count + 1,
                'last_retry_at' => now(),
            ]);

            // Return funds to merchant available balance using precision math
            $lockedMerchant->releasePayoutReservation((string) $lockedPayout->amount_usd);
        }, 5);

        Log::error('Payout transfer failed', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        // Notify merchant of failed payout
        $this->notificationService->notifyPayoutFailed($payout->fresh(), "{$errorCode}: {$errorMessage}");

        return [
            'success' => false,
            'error_code' => $errorCode,
            'error' => $errorMessage,
            'payout' => $payout->fresh(),
        ];
    }

    /**
     * Calculate crypto amount from USD
     */
    protected function calculateCryptoAmount(MerchantPayout $payout, Currency $currency): ?string
    {
        $rate = $currency->rate ?? 0;

        if ($rate <= 0) {
            // Try to fetch rate from pricing service
            try {
                $pricingService = app(PricingService::class);
                $rate = $pricingService->getRate($currency->symbol);
            } catch (\Exception $e) {
                Log::error('Could not fetch rate for payout', [
                    'currency' => $currency->symbol,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        if ($rate <= 0) {
            return null;
        }

        // Calculate: net_amount_usd / rate = crypto amount
        $cryptoAmount = bcdiv((string) $payout->net_amount_usd, (string) $rate, 18);

        return $cryptoAmount;
    }

    /**
     * Get hot wallet address for network
     */
    protected function getHotWalletAddress(Network $network): ?string
    {
        $networkSlug = strtolower($network->slug);
        $settingsKey = $this->networkSettingsMap[$networkSlug] ?? $networkSlug;
        
        return setting("{$settingsKey}.wallet");
    }

    /**
     * Get hot wallet private key for network
     */
    protected function getHotWalletPrivateKey(Network $network): ?string
    {
        $networkSlug = strtolower($network->slug);
        $settingsKey = $this->networkSettingsMap[$networkSlug] ?? $networkSlug;
        
        return setting("{$settingsKey}.private_key");
    }

    /**
     * Build explorer URL for transaction
     */
    protected function buildExplorerUrl(Network $network, string $txHash): string
    {
        $networkSlug = strtolower($network->slug);
        
        $explorers = [
            'erc' => 'https://etherscan.io/tx/',
            'erc20' => 'https://etherscan.io/tx/',
            'eth' => 'https://etherscan.io/tx/',
            'trc' => 'https://tronscan.org/#/transaction/',
            'trc20' => 'https://tronscan.org/#/transaction/',
            'trx' => 'https://tronscan.org/#/transaction/',
            'bep' => 'https://bscscan.com/tx/',
            'bep20' => 'https://bscscan.com/tx/',
            'bsc' => 'https://bscscan.com/tx/',
            'bnb' => 'https://bscscan.com/tx/',
            'polygon' => 'https://polygonscan.com/tx/',
            'matic' => 'https://polygonscan.com/tx/',
            'matic20' => 'https://polygonscan.com/tx/',
            'sol' => 'https://solscan.io/tx/',
            'solana' => 'https://solscan.io/tx/',
            'spl' => 'https://solscan.io/tx/',
        ];

        $baseUrl = $explorers[$networkSlug] ?? '';
        
        return $baseUrl ? $baseUrl . $txHash : '';
    }

    /**
     * Convert amount to wei/smallest unit
     */
    protected function toWei(string $amount, int $decimals): string
    {
        return bcmul($amount, bcpow('10', (string) $decimals, 0), 0);
    }

    /**
     * Retry a failed payout
     */
    public function retryPayout(MerchantPayout $payout): array
    {
        if ($payout->status !== MerchantPayout::STATUS_FAILED) {
            return [
                'success' => false,
                'error' => 'Only failed payouts can be retried',
            ];
        }

        // Re-reserve the balance with proper locking
        DB::transaction(function () use ($payout) {
            $lockedPayout = MerchantPayout::where('id', $payout->id)->lockForUpdate()->first();
            $lockedMerchant = Merchant::where('id', $payout->merchant_id)->lockForUpdate()->first();

            if (!$lockedPayout || !$lockedMerchant) {
                throw new \RuntimeException('Payout or merchant not found');
            }

            // Verify status hasn't changed
            if ($lockedPayout->status !== MerchantPayout::STATUS_FAILED) {
                throw new \RuntimeException('Payout status has changed');
            }

            // Verify sufficient balance using precision math
            if (math_compare($lockedMerchant->available_balance_usd ?? '0', (string) $lockedPayout->amount_usd) < 0) {
                throw new \RuntimeException('Insufficient balance for payout retry');
            }

            // Reserve balance using precision math
            $lockedMerchant->reserveForPayout((string) $lockedPayout->amount_usd);
            
            $lockedPayout->update([
                'status' => MerchantPayout::STATUS_APPROVED,
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
            ]);
        }, 5);

        // Process the payout again
        return $this->processAndSendPayout($payout->fresh());
    }
}
