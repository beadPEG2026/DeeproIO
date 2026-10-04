<?php

namespace App\Services\PaymentGateways\Coin\Bnb\Services;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Models\Currency\Currency;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class BnbService {

    private $_request = null;

    public function verifyCallback() {

        // Request instance
        $this->_request = request();

        try {

            /*
             * Validate request and data
             */
            if ($this->_request->get('hash') !== md5(config('app.url') . setting('system-monitor.ping'))) {
                throw new \Exception('invalid_request');
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
            case "bnb.deposit":
                return $this->handleDeposit('bnb');
            case "bep.deposit":
                return $this->handleDeposit('bep20');
            case "bnb.withdrawal" :
                return $this->handleWithdraw('bnb');
            case "bep.withdrawal":
                return $this->handleWithdraw('bep20');
        }

        return false;
    }

    public function handleDeposit($type, $data = null) {

        return DB::transaction(function () use ($type, $data) {

            $source = $data['deposit_id'];

            if($type == "bnb") {
                $symbol = 'BNB';
            } else {
                $symbol = $data['symbol'];
            }

            if(!$source) return false;

            $depositRepository = new DepositRepository();

            $network = (new NetworkRepository())->getIdBySlug($type);

            $deposit = $depositRepository->getBySource($source, $network->id);

            $amount = $data['amount'];

            $status = DEPOSIT_PENDING;

            $currencyRepository = new CurrencyRepository();

            if($type == "bnb") {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol($symbol, 'coin', false);
            } else {
                $currency = null;

                if (!empty($data['currency_id'])) {
                    $currency = Currency::with('networks')->where('id', $data['currency_id'])->first();
                }

                if (!$currency) {
                    $currency = $currencyRepository->getCurrencyByContractForNetwork($data['contract'], $network->id, 'coin', false);
                }

                if(!$currency && !empty($data['symbol'])) {
                    $currency = $currencyRepository->getCurrencyBySymbolForNetwork($data['symbol'], $network->id, 'coin', false);
                }
            }

            if(!$currency) return false;

            $wallet = (new WalletRepository())->getWalletByCurrency($data['user_id'], $currency->id, false);

            /*
             * If deposit not found
             */
            if (!$deposit) {

                if(math_compare($amount, $currency->min_deposit) < 0) {
                    $status = DEPOSIT_IGNORED;
                }

                $data = [
                    'deposit_id' => generate_uuid(),
                    'txn' => $data['hash'],
                    'source_id' => $data['deposit_id'],
                    'currency_id' => $currency->id,
                    'type' => 'coin',
                    'network_id' => $network->id,
                    'amount' => $amount,
                    'full_amount' => $data['full_amount'],
                    'network_fee' => $data['fee'],
                    'address' => $data['address'],
                    'user_id' => $wallet->user_id,
                    'confirms' => $data['confirms'],
                    'status' => $status,
                    'initial_raw' => null
                ];

                $storedDeposit = $depositRepository->store($data);

                $deposit = $depositRepository->getDeposit($storedDeposit->id);

                // Calculate system fee
                $systemFee = (new CurrencyService())->calculateSystemFee($network->slug, $currency, $deposit->amount);

                // Store system fee
                $deposit->system_fee = $systemFee;
                $deposit->update();

                if($status != DEPOSIT_IGNORED) {
                    event(new DepositUpdated($deposit->fresh(), 'received'));
                }

                return true;
            }

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    public function handleWithdraw($withdrawal, $type) {
        if (!$withdrawal instanceof \App\Models\Withdrawal\Withdrawal) return false;
        return (new \App\Repositories\Withdrawal\WithdrawalRepository())->completeVerified((int) $withdrawal->id, (string) $withdrawal->txn);
    }
}
