<?php
namespace App\Services\PaymentGateways\Coin\Ton\Api;

use App\Helpers\PaymentGateways\Ton\TonNodeHelper;
use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Setting;

class TonGateway implements CoinGatewayInterface
{
    /**
     * Create a system wallet via Node bridge
     * Expected Node response: { address: string, private_key: string }
     */
    public function createTonAddress()
    {
        try {
            $response = Http::connectTimeout(5)->timeout(20)->get(TonNodeHelper::route('wallet.create'));
            if ($response->successful()) {
                $body = $response->json();
                if (isset($body['address'])) {
                    return [
                        'address' => $body['address'],
                        'private_key' => $body['private_key'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error($e);
        }
        return false;
    }

    /**
     * Get TON balance via Node bridge
     * Returns ['balance' => float] on success, or [] on failure
     */
    public function getBalance($address)
    {
        try {
            if (!$address) {
                return [];
            }
            $response = Http::connectTimeout(5)->timeout(20)->post(TonNodeHelper::route('wallet.balance.ton'), [
                'address' => $address,
            ]);
            if ($response->ok()) {
                $body = $response->json();
                if (($body['success'] ?? false) && isset($body['message'])) {
                    // Bridge returns TON amount in 'message'
                    return ['balance' => (float) $body['message']];
                }
            }
        } catch (\Exception $e) {
            Log::error($e);
        }
        return [];
    }

    /**
     * Withdraw TON via Node bridge
     * Bridge returns { success: bool, txHash?: string, error?: string }
     */
    public function withdraw($withdrawal_id, $toAddress, $amount, $paymentId)
    {
        $amount = math_formatter($amount, 9);

        try {
            $response = Http::timeout(60)->post(TonNodeHelper::route('wallet.withdraw.ton'), [
                'id' => $withdrawal_id,
                'amount' => $amount,
                'address' => setting('ton.wallet'),
                'private_key' => setting('ton.private_key'),
                'to' => $toAddress,
                'fee' => false,
                'memo' => $paymentId ?? null,
            ]);

            $body = $response->json();

            if ($response->successful() && ($body['success'] ?? false)) {
                $txHash = $body['txHash'] ?? null;
                return [
                    'status' => STATUS_OK,
                    'source' => $withdrawal_id,
                    'message' => 'Withdrawal successful',
                    'txn' => $txHash, // Used by WithdrawalRepository to set withdrawal txn
                ];
            }

            // Bridge returned failure
            $error = $body['error'] ?? 'Unknown error';
            Log::error('TON withdrawal bridge error', [
                'withdrawal_id' => $withdrawal_id,
                'error' => $error,
            ]);

            return [
                'status' => STATUS_VALIDATION_ERROR,
                'source' => '',
                'message' => $error,
                'txHash' => null,
            ];

        } catch (\Exception $e) {
            Log::error('TON withdrawal exception', [
                'withdrawal_id' => $withdrawal_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => STATUS_VALIDATION_ERROR,
                'source' => '',
                'message' => $e->getMessage(),
                'txHash' => null,
            ];
        }
    }

    /**
     * Validate TON address via Node bridge
     */
    public function validateAddress($address): bool
    {
        try {
            if (!$address) return false;
            $response = Http::connectTimeout(5)->timeout(20)->post(TonNodeHelper::route('wallet.validate.ton'), [
                'address' => $address,
            ]);
            if ($response->ok()) {
                $body = $response->json();
                return (bool) ($body['success'] ?? false);
            }
        } catch (\Exception $e) {
            Log::error($e);
        }
        return false;
    }

    /**
     * Fetch recent TON transactions via Node bridge
     * Returns ['transactions' => [...]] on success, or [] on failure
     */
    public function getTransactions($address)
    {
        try {
            if (!$address) return [];
            $response = Http::connectTimeout(5)->timeout(20)->post(TonNodeHelper::route('wallet.transactions.ton'), [
                'address' => $address,
                'limit' => 50,
            ]);
            if ($response->ok()) {
                $body = $response->json();
                if (($body['success'] ?? false) && isset($body['transactions']) && is_array($body['transactions'])) {
                    return $body['transactions'];
                }
            }
        } catch (\Exception $e) {
            Log::error($e);
        }
        return [];
    }

    public function ping() {
        $response = Http::connectTimeout(5)->timeout(20)->get(TonNodeHelper::route('wallet.create'));
        return $response->json();
    }
}
