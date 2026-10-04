<?php

namespace App\Services\PaymentGateways\Coin\Coinpayments\Services;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Models\Network\Network;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Coin\Coinpayments\Api\CoinpaymentsGateway;
use App\Services\PaymentGateways\Coin\Coinpayments\Model\CoinpaymentsCurrency;
use App\Services\PaymentGateways\Coin\Coinpayments\Resources\CoinpaymentsCollection;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class CoinpaymentsService {

    private $_request = null;
    private $merchant_id = null;
    private $ipn_secret = null;
    private $encode_type = 'sha512';
    private $type;

    public function __construct() {

        $settings = Setting::get('coinpayments');

        $this->ipn_secret = $settings['ipn_secret'] ?? null;
        $this->merchant_id = $settings['merchant_id'] ?? null;

    }

    public function getCoins() {

        $coins = CoinpaymentsCurrency::get();

        return new CoinpaymentsCollection($coins);
    }

    public function verifyCallback() {

        // Request instance
        $this->_request = request();

        try {

            /*
             * Validate request and data
             */
            if (!$this->_request->has(["merchant", "status", "status_text", "ipn_mode"])) {
                throw new \Exception("invalid_post_data");
            }

            if (empty($this->_request->server('HTTP_HMAC'))) {
                throw new \Exception('no_hmac_signature_found');
            }

            if ($this->_request->get('ipn_mode') != 'hmac') {
                throw new \Exception('invalid_ipn_mode');
            }

            $blob = file_get_contents('php://input');

            if (empty($blob) || !$blob) {
                throw new \Exception('invalid_post_input_data');
            }

            if (!hash_equals(hash_hmac($this->encode_type, $blob, $this->ipn_secret), $this->_request->server('HTTP_HMAC'))) {
                throw new \Exception('invalid_signature');
            }

            if ($this->_request->get('merchant') !== $this->merchant_id) {
                throw new \Exception('invalid_merchant');
            }

            $currency = (new CurrencyRepository())->getCurrencyBySymbol($this->_request->get('currency'), 'coin', false);

            if (!$currency) {
                throw new \Exception('invalid_currency');
            }

            /*
            * END Validate request and data
            */

            return true;

        } catch (\Exception $e) {

            Log::error($e);

            return false;
        }
    }

    public function handleCallback() {

        // Request instance
        $this->_request = request();

        // Get ipn type
        $this->type = $this->_request->get('ipn_type');

        switch ($this->type) {
            case "deposit":
                $this->handleDeposit(); break;
            case "withdrawal" :
                $this->handleWithdraw(); break;
            default:
                return false;
        }

        return false;
    }

    public function handleDeposit() {

        DB::transaction(function () {

            if(Network::where('id', NETWORK_COINPAYMENTS)->where('deposit_status', false)->count()) {
                return;
            }

            $source = request()->get('deposit_id', null);

            $depositRepository = new DepositRepository();

            $network = (new NetworkRepository())->getIdBySlug('coinpayments');

            $deposit = $depositRepository->getBySource($source, $network->id);

            $status = request()->get('status') >= COINPAYMENTS_DEPOSIT_CONFIRMED ? DEPOSIT_CONFIRMED : DEPOSIT_PENDING;
            $amount = request()->get('amount');

            $payment_id = request()->get('dest_tag', null);

            $currency = (new CurrencyRepository())->getCurrencyBySymbol(request()->get('currency'), 'coin', false);
            $wallet = (new WalletRepository())->getWalletByAddress(request()->get('address'), $payment_id, $network->id, $currency->id);

            $depositCredited = false;

            /*
             * If deposit not found
             */
            if (!$deposit) {

                $data = [
                    'deposit_id' => generate_uuid(),
                    'txn' => request()->get('txn_id'),
                    'source_id' => request()->get('deposit_id'),
                    'currency_id' => $currency->id,
                    'type' => 'coin',
                    'network_id' => $network->id,
                    'amount' => $amount,
                    'network_fee' => request()->get('fee', 0),
                    'address' => request()->get('address'),
                    'payment_id' => request()->get('dest_tag'),
                    'user_id' => $wallet->user_id,
                    'confirms' => request()->get('confirms', 0),
                    'status' => $status,
                    'initial_raw' => json_encode(request()->all())
                ];

                $storedDeposit = $depositRepository->store($data);

                $deposit = $depositRepository->getDeposit($storedDeposit->id);

                event(new DepositUpdated($deposit, 'received'));

                if(math_compare($amount, $currency->min_deposit) >= 0) {
                    $depositCredited = true;
                }

                // Reflect balances
                if ($depositCredited && $status == DEPOSIT_CONFIRMED) {

                    // Calculate system fee
                    $amount = $this->calculateNetworkFee($deposit, $amount);

                    // Calculate system fee
                    $systemFee = (new CurrencyService())->calculateSystemFee($network->slug, $currency, $amount);
                    $amount = math_sub($amount, $systemFee);

                    // Store system fee
                    $deposit->system_fee = $systemFee;
                    $deposit->update();

                    $creditedAmount = (new DepositCreditService())->credit($wallet, $amount);

                    // Notify user
                    Mail::to($wallet->user)->queue(new DepositReceived($wallet->user, $creditedAmount, $currency->symbol));

                    // Admin Email Notification
                    $adminEmail = Setting::get('notification.admin_email', false);
                    $notificationAllowed = Setting::get('notification.crypto_deposits', false);

                    if($adminEmail && $notificationAllowed) {
                        $route = route('admin.reports.deposits') . "?search=" . $deposit->deposit_id;
                        Mail::to($adminEmail)->queue(new AdminDepositReceived($amount, $currency->symbol, $route));
                    }
                    // END Admin Email Notification
                }

            } elseif($deposit->status != DEPOSIT_CONFIRMED) {

                $data = [
                    'status' => $status,
                    'network_fee' => request()->get('fee', 0),
                    'confirms' => request()->get('confirms'),
                    'raw' => json_encode(request()->all()),
                ];

                $depositRepository->update($deposit, $data);

                $deposit = $deposit->fresh();

                event(new DepositUpdated($deposit, 'updated'));

                // Reflect balances
                if ($status == DEPOSIT_CONFIRMED) {

                    if(math_compare($deposit->amount, $currency->min_deposit) > 0) {
                        $depositCredited = true;
                    }

                    if($depositCredited) {

                        // Calculate system fee
                        $amount = $this->calculateNetworkFee($deposit, $deposit->amount);

                        // Calculate system fee
                        $systemFee = (new CurrencyService())->calculateSystemFee($network->slug, $currency, $amount);
                        $amount = math_sub($amount, $systemFee);

                        // Store system fee
                        $deposit->system_fee = $systemFee;
                        $deposit->update();

                        $creditedAmount = (new DepositCreditService())->credit($wallet, $amount);

                        // Notify user
                        Mail::to($wallet->user)->queue(new DepositReceived($wallet->user, $creditedAmount, $currency->symbol));

                        // Admin Email Notification
                        $adminEmail = Setting::get('notification.admin_email', false);
                        $notificationAllowed = Setting::get('notification.crypto_deposits', false);

                        if($adminEmail && $notificationAllowed) {
                            $route = route('admin.reports.deposits') . "?search=" . $deposit->deposit_id;
                            Mail::to($adminEmail)->queue(new AdminDepositReceived($amount, $currency->symbol, $route));
                        }
                        // END Admin Email Notification
                    }
                }
            }

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    private function calculateNetworkFee($deposit, $amount) {

        $isSystemFeePayer = setting('coinpayments.pay_deposit_fee', false);

        if($isSystemFeePayer) {
            return $amount;
        }

        return math_sub($amount, $deposit->network_fee);
    }

    public function handleWithdraw() {

        DB::transaction(function () {

            $withdrawalRepository = new WithdrawalRepository();

            $network = (new NetworkRepository())->getIdBySlug('coinpayments');

            $withdrawal = $withdrawalRepository->getBySource(request()->get('id'), $network->id);

            if(!$withdrawal) return false;

            $status = request()->get('status');

            $walletService = new WalletService();

            $wallet = (new WalletRepository())->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency->id);

            if ($status == COINPAYMENTS_WITHDRAW_CONFIRMED && $withdrawal->status == WITHDRAWAL_WAITING_PROVIDER_APPROVAL) {

                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                $withdrawal->txn = request()->get('txn_id');
                $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
                $withdrawal->raw = json_encode(request()->all());
                $withdrawal->update();

                // Notify user
                Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed($withdrawal->user, $withdrawal->amount, $withdrawal->currency->symbol, $withdrawal->txn));

                // Admin Email Notification
                $adminEmail = Setting::get('notification.admin_email', false);
                $notificationAllowed = Setting::get('notification.crypto_withdrawals', false);

                if($adminEmail && $notificationAllowed) {
                    $route = route('admin.reports.withdrawals') . "?search=" . $withdrawal->withdrawal_id;
                    Mail::to($adminEmail)->queue(new AdminWithdrawalReceived($withdrawal->amount, $withdrawal->currency->symbol, $route));
                }
                // END Admin Email Notification

            } elseif ($status < COINPAYMENTS_WITHDRAW_FAILED) {

                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                $walletService->increase($wallet, $withdrawal->amount);

                $withdrawal->status = WITHDRAWAL_FAILED;
                $withdrawal->raw = json_encode(request()->all());
                $withdrawal->update();
            }

            event(new WithdrawalUpdated($withdrawal));

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    public function syncCoins() {
        (new CoinpaymentsGateway())->syncAvailableCoins();
    }
}
