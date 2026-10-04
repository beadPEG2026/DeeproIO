<?php
namespace App\Services\PaymentGateways\Coin\Coinpayments\Api;

use App\Interfaces\PaymentsGateways\Coin\CoinGatewayInterface;
use App\Services\PaymentGateways\Coin\Coinpayments\Model\CoinpaymentsCurrency;
use Illuminate\Support\Facades\Log;

class CoinpaymentsGateway implements CoinGatewayInterface
{
    /**
     * Used to call request to Coinpayments API
     *
     * @param $uri
     * @param array $params
     * @return false|mixed
     */
    public function request($uri, $params = [])
    {
        try {
            return get_coinpayments_request($uri, $params);

        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    public function get($uri, $params = [])
    {
        try {

            return get_coinpayments_request($uri, $params, "GET");

        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
    }

    /**
     * Used to sync all available coins with ipn feature support
     */
    public function syncAvailableCoins() {
        try {

            // Get currencies
            $currencies = $this->get('currencies');

            // Remove all currencies before sync
            if(is_array($currencies) && !empty($currencies)) {
                CoinpaymentsCurrency::truncate();
            }

            // Iterate each currency to store
            foreach ($currencies as $name=>$currency) {

                // If it is fiat skip
                if($currency->type == "fiat") continue;

                // If it is not used for payments skip it
                if(!$currency->isEnabledForPayment) continue;

                $hasPaymentId = false;

                $explorer = $currency->urls->explorers[0] ?? null;

                if($explorer) {
                    if (str_contains($explorer, 'etherscan')) {
                        $explorer .= '/tx/%txid%';
                    } elseif (str_contains($explorer, 'basescan')) {
                        $explorer .= '/tx/%txid%';
                    } elseif (str_contains($explorer, 'polygonscan')) {
                        $explorer .= '/tx/%txid%';
                    } elseif (str_contains($explorer, 'binance')) {
                        $explorer .= '/tx/%txid%';
                    } elseif (str_contains($explorer, 'solana')) {
                        $explorer .= '/tx/%txid%';
                    } elseif (str_contains($explorer, 'tronscan')) {
                        $explorer .= '#/transaction/%txid%';
                    } else {
                        $explorer .= '/transaction/%txid%';
                    }
                }

                // Create or update currency
                CoinpaymentsCurrency::updateOrCreate(
                    ['symbol' => $currency->symbol],
                    [
                        'name' => $currency->name,
                        'symbol' => $currency->symbol,
                        'fee' => 0,
                        'confirmations' => $currency->requiredConfirmations,
                        'status' => $currency->status,
                        'blockchain_url' => $explorer,
                        'has_payment_id' => $hasPaymentId
                    ]
                );
            }

        } catch (\Exception $exception) {
            Log::error($exception);
        }
    }

    /**
     * Used to generate address wallet per currency
     * @param $symbol
     * @return array
     */
    public function createAddress($symbol, $userId) {

        // Define ipn url
        $ipn = route('coinpayments.ipn');

        // Get new wallet address
        $wallet = $this->request('merchant/wallets', [
            'currency' => $symbol,
            'usePermanentAddresses' => true,
            'label' => (string)$userId
        ]);

        if(isset($wallet->walletId)) {
            $address = $this->request('merchant/wallets/'. $wallet->walletId .'/addresses', [
                'notificationUrl' => 'https://webhook.site/#!/view/38839a45-262d-4075-b601-e2ccea58fa04',
                //'notificationUrl' => $ipn,
                'label' => (string)$userId
            ]);

            if(isset($address->networkAddress)) {
                return [
                    'address' => $address->networkAddress,
                    'dest_tag' => null,
                ];
            }
        }

        // Return empty address
        return [];
    }

    /**
     * Used to generate data to withdraw
     * @param $address
     * @param $paymentId
     * @param $amount
     * @param $currency
     * @return array
     */
    public function withdraw($address, $paymentId, $amount, $currency)
    {
        $params = [
            'ipn_url' => route('coinpayments.ipn'),
            'address' => $address,
            'amount' => $amount,
            'currency' => $currency->alt_symbol,
            'auto_confirm' => COINPAYMENTS_AUTO_CONFIRM,
            'add_tx_fee' => COINPAYMENTS_AUTO_CONFIRM,
        ];

        if ($paymentId) {
            $params['dest_tag'] = $paymentId;
        }

        $response = $this->request('create_withdrawal', $params);

        $status = (isset($response->error) && $response->error !== "ok") ? STATUS_VALIDATION_ERROR : STATUS_OK;

        $message = $response;
        $source = '';

        if($status == STATUS_VALIDATION_ERROR) {
            $message = $response->error;
        } else {
            $source = $response->result->id;
        }

        return [
            'status' => $status,
            'source' => $source,
            'message' => $message,
        ];
    }

    /**
     * Used to get system wallet balance
     * @return array
     */
    public function getBalance()
    {
        return $this->request('balances');
    }
}
