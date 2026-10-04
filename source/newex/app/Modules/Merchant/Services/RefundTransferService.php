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
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantOrphanPayment;
use App\Modules\Merchant\Models\MerchantRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefundTransferService
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
     * Process and send a refund
     */
    public function processAndSendRefund(MerchantRefund $refund): array
    {
        Log::info('Starting refund transfer', [
            'refund_id' => $refund->id,
            'amount_crypto' => $refund->amount_crypto,
            'destination' => $refund->destination_address,
        ]);

        // Get network and currency from invoice or orphan payment
        $network = null;
        $currency = null;

        if ($refund->invoice_id) {
            $invoice = MerchantInvoice::find($refund->invoice_id);
            if ($invoice) {
                $network = Network::find($invoice->network_id);
                $currency = Currency::find($invoice->currency_id);
            }
        } elseif ($refund->orphan_payment_id) {
            $orphan = MerchantOrphanPayment::with('address')->find($refund->orphan_payment_id);
            if ($orphan) {
                // Get network from the deposit address
                if ($orphan->address) {
                    $network = Network::find($orphan->address->network_id);
                }
                $currency = Currency::find($orphan->currency_id);
            }
        }

        // Try destination network if set
        if (!$network && $refund->destination_network_id) {
            $network = Network::find($refund->destination_network_id);
        }

        if (!$network || !$currency) {
            return $this->handleFailure($refund, 'MISSING_NETWORK_CURRENCY', 'Network or currency not found');
        }

        // Get hot wallet configuration
        $hotWalletAddress = $this->getHotWalletAddress($network);
        $hotWalletPrivateKey = $this->getHotWalletPrivateKey($network);

        if (!$hotWalletAddress || !$hotWalletPrivateKey) {
            return $this->handleFailure($refund, 'HOT_WALLET_NOT_CONFIGURED', 'Hot wallet not configured for network: ' . $network->name);
        }

        // Verify refund amount
        $amount = (string) $refund->amount_crypto;
        if (math_compare($amount, '0') <= 0) {
            return $this->handleFailure($refund, 'INVALID_AMOUNT', 'Refund amount must be positive');
        }

        // Send the funds based on network type
        $result = $this->sendFunds(
            $network,
            $currency,
            $hotWalletAddress,
            $hotWalletPrivateKey,
            $refund->destination_address,
            $amount
        );

        if ($result['success']) {
            return $this->handleSuccess($refund, $result, $network);
        } else {
            return $this->handleFailure($refund, $result['error_code'] ?? 'TRANSFER_FAILED', $result['error'] ?? 'Unknown error');
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
            Log::error('Refund transfer exception', [
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
        Log::info("Refund {$networkType} bridge response", [
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

        if (isset($data['error']) || isset($data['message'])) {
            return [
                'success' => false,
                'error' => $data['error'] ?? $data['message'],
                'error_code' => $data['code'] ?? 'BRIDGE_ERROR',
            ];
        }

        return [
            'success' => false,
            'error' => 'Could not determine transaction result from bridge response',
            'error_code' => 'UNKNOWN_RESPONSE',
        ];
    }

    /**
     * Handle successful transfer
     */
    protected function handleSuccess(MerchantRefund $refund, array $result, Network $network): array
    {
        $txnHash = $result['txn_hash'];
        $explorerUrl = $this->buildExplorerUrl($network, $txnHash);

        DB::transaction(function () use ($refund, $txnHash) {
            $lockedRefund = MerchantRefund::where('id', $refund->id)->lockForUpdate()->first();

            $lockedRefund->txn_hash = $txnHash;
            $lockedRefund->status = MerchantRefund::STATUS_BROADCAST;
            $lockedRefund->broadcast_at = now();
            $lockedRefund->save();

            // Update invoice status
            if ($lockedRefund->invoice_id) {
                $invoice = MerchantInvoice::where('id', $lockedRefund->invoice_id)->lockForUpdate()->first();
                if ($invoice) {
                    $invoice->transitionTo(MerchantInvoice::STATUS_REFUNDED, 'Refund broadcast');
                }
            }

            // Update orphan payment status
            if ($lockedRefund->orphan_payment_id) {
                $orphan = MerchantOrphanPayment::where('id', $lockedRefund->orphan_payment_id)->lockForUpdate()->first();
                if ($orphan) {
                    $orphan->resolveAsRefund($txnHash);
                }
            }
        }, 5);

        // Mark as completed (for now, we consider broadcast = completed)
        // In production, you might want to wait for confirmations
        $refund->markCompleted();

        Log::info('Refund transfer successful', [
            'refund_id' => $refund->id,
            'txn_hash' => $txnHash,
            'explorer_url' => $explorerUrl,
        ]);

        // Notify about successful refund
        $this->notificationService->notifyRefundCompleted($refund->fresh());

        return [
            'success' => true,
            'txn_hash' => $txnHash,
            'explorer_url' => $explorerUrl,
            'refund' => $refund->fresh(),
        ];
    }

    /**
     * Handle failed transfer
     */
    protected function handleFailure(MerchantRefund $refund, string $errorCode, string $errorMessage): array
    {
        DB::transaction(function () use ($refund, $errorMessage) {
            $lockedRefund = MerchantRefund::where('id', $refund->id)->lockForUpdate()->first();
            $lockedRefund->markFailed($errorMessage);

            // Restore merchant balance if it was deducted
            if ($lockedRefund->invoice_id) {
                $invoice = MerchantInvoice::find($lockedRefund->invoice_id);
                if ($invoice && $invoice->settled_at !== null) {
                    $merchant = Merchant::where('id', $lockedRefund->merchant_id)->lockForUpdate()->first();
                    if ($merchant) {
                        $merchant->available_balance_usd = math_sum(
                            $merchant->available_balance_usd ?? '0',
                            (string) $lockedRefund->amount_usd
                        );
                        $merchant->save();
                    }
                }

                // Restore invoice status
                if ($invoice && $invoice->status === MerchantInvoice::STATUS_REFUNDING) {
                    $invoice->transitionTo($invoice->previous_status ?? MerchantInvoice::STATUS_PAID, 'Refund failed: ' . $errorMessage);
                }
            }

            if ($lockedRefund->orphan_payment_id) {
                $orphan = MerchantOrphanPayment::where('id', $lockedRefund->orphan_payment_id)->lockForUpdate()->first();
                if ($orphan) {
                    $orphan->status = MerchantOrphanPayment::STATUS_PENDING;
                    $orphan->resolution_type = null;
                    $orphan->save();
                }
            }
        }, 5);

        Log::error('Refund transfer failed', [
            'refund_id' => $refund->id,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        return [
            'success' => false,
            'error_code' => $errorCode,
            'error' => $errorMessage,
            'refund' => $refund->fresh(),
        ];
    }

    /**
     * Retry a failed refund
     */
    public function retryRefund(MerchantRefund $refund): array
    {
        if ($refund->status !== MerchantRefund::STATUS_FAILED) {
            return [
                'success' => false,
                'error' => 'Only failed refunds can be retried',
            ];
        }

        DB::transaction(function () use ($refund) {
            $lockedRefund = MerchantRefund::where('id', $refund->id)->lockForUpdate()->first();

            if ($lockedRefund->status !== MerchantRefund::STATUS_FAILED) {
                throw new \RuntimeException('Refund status has changed');
            }

            // Re-deduct from merchant balance if needed
            if ($lockedRefund->invoice_id) {
                $invoice = MerchantInvoice::find($lockedRefund->invoice_id);
                if ($invoice && $invoice->settled_at !== null) {
                    $merchant = Merchant::where('id', $lockedRefund->merchant_id)->lockForUpdate()->first();
                    if ($merchant && math_compare($merchant->available_balance_usd ?? '0', (string) $lockedRefund->amount_usd) >= 0) {
                        $merchant->available_balance_usd = math_sub(
                            $merchant->available_balance_usd,
                            (string) $lockedRefund->amount_usd
                        );
                        $merchant->save();
                    } else {
                        throw new \RuntimeException('Insufficient merchant balance for refund retry');
                    }
                }
            }

            $lockedRefund->status = MerchantRefund::STATUS_PROCESSING;
            $lockedRefund->failure_reason = null;
            $lockedRefund->failed_at = null;
            $lockedRefund->save();
        }, 5);

        return $this->processAndSendRefund($refund->fresh());
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
}
