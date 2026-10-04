<?php

namespace App\Services\PaymentGateways\Coin\Tron\Services;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
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

class TronService {

    private $_request = null;

    public function handleDeposit($type, $data = null) {

        return DB::transaction(function () use ($type, $data) {

            $source = $data['deposit_id'];

            if($type == "trx") {
                $symbol = 'TRX';
            } else {
                $symbol = $data['symbol'];
            }

            if(!$source) return false;

            $depositRepository = new DepositRepository();

            $network = (new NetworkRepository())->getIdBySlug($type);

            $deposit = $depositRepository->getBySource($source, $network->id);

            $amount = $data['amount'];

            $status = DEPOSIT_PENDING;

            if($type == "trx") {
                $currency = (new CurrencyRepository())->getCurrencyBySymbol($symbol, 'coin', false);
            } else {
                $currency = (new CurrencyRepository())->getCurrencyByContract($data['contract'], 'coin', false);
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

        return DB::transaction(function () use ($withdrawal, $type) {

            $walletService = new WalletService();

            $wallet = (new WalletRepository())->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency->id);

            if ($withdrawal->status == WITHDRAWAL_CONFIRMED_BY_PROVIDER) {

                if($withdrawal->source_id != "system") {
                    $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                }

                try {

                    if($withdrawal->source_id != "system") {
                        // Notify user
                        Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed($withdrawal->user, $withdrawal->amount, $withdrawal->currency->symbol, $withdrawal->txn));
                    }

                } catch (\Exception $e) {

                }

            } elseif ($withdrawal->status < BNB_WITHDRAW_FAILED) {
                if($withdrawal->source_id != "system") {
                    $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                    $walletService->increase($wallet, $withdrawal->amount);
                }
            }

            event(new WithdrawalUpdated($withdrawal));

            return true;

        }, DB_REPEAT_AFTER_DEADLOCK);
    }
}
