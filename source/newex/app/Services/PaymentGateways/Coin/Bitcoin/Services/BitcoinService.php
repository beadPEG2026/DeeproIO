<?php

namespace App\Services\PaymentGateways\Coin\Bitcoin\Services;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Models\Deposit\Deposit;
use App\Models\Network\Network;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class BitcoinService {

    private $_request = null;
    private $_symbol = null;
    private $_network = null;

    public function verifyCallback() {
        return true;
    }

    public function handleCallback($symbol) {

        // Request instance
        if($symbol==='BTC' && app(\App\Services\Wallet\BitcoinWalletManager::class)->active()){
            $ok=false;
            foreach(DB::table('bitcoin_wallets')->where('scan_enabled',true)->get() as $wallet)$ok=app(\App\Services\Deposit\BitcoinManagedDeposits::class)->ingest($wallet,(string)request('txn'))||$ok;
            return $ok;
        }
        $this->_request = request();

        $this->_symbol = $symbol;

        $this->_network = $symbol == "BTC" ? 'btc' : 'btc_fork';

        return $this->handleDeposit();
    }

    public function handleDeposit() {

        return DB::transaction(function () {

            $shouldBeIgnored = false;

            if(Network::where('id', NETWORK_BTC)->where('deposit_status', false)->count()) {
                $shouldBeIgnored = true;
            }

            $txn = $this->_request->get('txn', null);

            if(!$txn) return false;

            $currency = (new CurrencyRepository())->getCurrencyBySymbol($this->_symbol, 'coin', false);

            if(!$currency) return false;

            $networks = [];

            $network = (new NetworkRepository())->getIdBySlug($this->_network);

            if($network) {
                $networks[] = $network->id;
            }

            $brcNetwork = (new NetworkRepository())->getIdBySlug('brc20');

            if($brcNetwork) {
                $networks[] = $brcNetwork->id;
            }

            $bitcoinTx = \App\Services\PaymentGateways\Coin\Bitcoin\Api\BitcoinGateway::client()->gettransaction($txn);

            if ($bitcoinTx->hasError()) {
                Log::error("BTC Deposit exception: $txn");
                return false;
            }

            $bitcoinTxData = $bitcoinTx->result();

            $confirmations = intval($bitcoinTxData['confirmations']);

            $depositRepository = new DepositRepository();

            $deposit = $depositRepository->getByTxn($txn, $network->id);

            if($deposit) {

                if($confirmations >= 1) {
                    $this->handleBrcToken($brcNetwork, $bitcoinTx, $deposit->user_id, $deposit->address, $confirmations);
                }

                if($deposit->status == DEPOSIT_PENDING) {

                    if($confirmations >= max(\App\Services\Deposit\ConfirmationPolicy::minimum('bitcoin'),(int)$currency->min_deposit_confirmation)) {

                        $amount = math_sub($deposit->amount, $deposit->system_fee);

                        $deposit->status = DEPOSIT_CONFIRMED;
                        $deposit->confirms = $confirmations;
                        $deposit->update();

                        $wallet = (new WalletRepository())->getWalletByCurrency($deposit->user_id, $currency->id, false);

                        $creditedAmount = (new DepositCreditService())->credit($wallet, $amount);

                        try {
                            // Notify user
                            Mail::to($wallet->user)->queue(new DepositReceived($wallet->user, $creditedAmount, $currency->symbol));

                            // Admin Email Notification
                            $adminEmail = Setting::get('notification.admin_email', false);
                            $notificationAllowed = Setting::get('notification.crypto_deposits', false);

                            if ($adminEmail && $notificationAllowed) {
                                $route = route('admin.reports.deposits') . "?search=" . $deposit->deposit_id;
                                Mail::to($adminEmail)->queue(new AdminDepositReceived($deposit->amount, $currency->symbol, $route));
                            }
                            // END Admin Email Notification

                        } catch (\Exception $e) {
                            Log::error('Deposit Notify Email Exception');
                        }
                    }
                }

                return true;
            }

            foreach ($bitcoinTxData['details'] as $tx) {

                $address = $tx['address'];

                $detectAddress = (new WalletRepository())->getWalletByAddress($address, null, $networks, false);

                if (!$detectAddress) {
                    continue;
                }

                $wallet = (new WalletRepository())->getWalletByCurrency($detectAddress->user_id, $currency->id, false);

                if (!$wallet) {
                    continue;
                }

                //Check if the tx address exists in our database
                $amount = number_format($tx['amount'], 18);

                $status = DEPOSIT_PENDING;

                /*
                 * If deposit not found
                 */
                if (!$deposit) {

                    if ($shouldBeIgnored || math_compare($amount, $currency->min_deposit) < 0) {
                        $status = DEPOSIT_IGNORED;
                    }

                    $storedDeposit = $depositRepository->store([
                        'deposit_id' => generate_uuid(),
                        'txn' => $txn,
                        'source_id' => generate_string(),
                        'currency_id' => $currency->id,
                        'type' => 'coin',
                        'network_id' => $network->id,
                        'amount' => $amount,
                        'full_amount' => $amount,
                        'network_fee' => 0,
                        'address' => $address,
                        'user_id' => $wallet->user_id,
                        'confirms' => $confirmations,
                        'wallet_transfer_status' => 'processed',
                        'status' => $status,
                        'initial_raw' => null
                    ]);

                    $deposit = $depositRepository->getDeposit($storedDeposit->id);

                    // Calculate system fee
                    $systemFee = (new CurrencyService())->calculateSystemFee($network->slug, $currency, $deposit->amount);

                    // Store system fee
                    $deposit->system_fee = $systemFee;
                    $deposit->update();

                    if ($status != DEPOSIT_IGNORED) {
                        event(new DepositUpdated($deposit->fresh(), 'received'));
                    }
                }

            }

            return true;

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    public function handleBrcToken($network, $bitcoinTx, $userId, $address, $confirmations) {

        if(!$network) return;

        try {

            $data = $bitcoinTx->get();

            $rawDecoded = bitcoind()->decoderawtransaction($data['hex'])->toArray();


            $count = count($rawDecoded['vin']);

            foreach($rawDecoded['vin'] as $key=>$raw) {

                if (--$count <= 0) {
                    break;
                }

                $txn = $raw['txid'];

                if(Deposit::where('txn', $txn)->first()) {
                    continue;
                }

                $prevResult = Http::withBody(json_encode([
                    "method" => "getrawtransaction",
                    "params" => [
                        $txn,
                        true
                    ]
                ]))->post(config('bitcoind.default.quicknode'))->json();

                $decodeHex = hex2bin($prevResult['result']['vin'][0]['txinwitness'][1]);

                preg_match_all('#\{(?:[^{}]|(?R))*\}#s', $decodeHex, $matches);
                $json = array_filter($matches[0], 'json_validate');

                if (isset($json[0])) {

                    $inscribe = json_decode($json[0], true);

                    if (!array_key_exists("p", $inscribe)
                        || !array_key_exists("op", $inscribe)
                        || !array_key_exists("tick", $inscribe)
                        || !array_key_exists("amt", $inscribe)
                        || $inscribe['op'] !== "transfer"
                        || $inscribe['p'] !== "brc-20"
                    ) {
                        return;
                    }

                    $currency = (new CurrencyRepository())->getCurrencyBySymbol($inscribe['tick']);

                    if(!$currency || !in_array(NETWORK_BRC20, $currency->networks->pluck('id')->toArray())) {
                        return;
                    }

                    $ordinalApi = new OrdinalApiGateway();
                    $inscription = $ordinalApi->getInscription($inscribe['tick'], $txn);

                    if(!$inscription) {
                        Log::error('Inscription not valid at ' . $txn);
                        return;
                    }

                    $amount = $inscription['amount'];

                    $systemFee = (new CurrencyService())->calculateSystemFee($network->slug, $currency, $amount);

                    $amount = math_sub($amount, $systemFee);

                    $depositId = generate_uuid();
                    $storedDeposit = (new DepositRepository())->store([
                        'deposit_id' => $depositId,
                        'txn' => $txn,
                        'source_id' => generate_string(),
                        'currency_id' => $currency->id,
                        'type' => 'coin',
                        'network_id' => $network->id,
                        'system_fee' => $systemFee,
                        'amount' => $amount,
                        'full_amount' => $amount,
                        'network_fee' => 0,
                        'address' => $address,
                        'user_id' => $userId,
                        'confirms' => $confirmations,
                        'status' => DEPOSIT_CONFIRMED,
                        'initial_raw' => null
                    ]);

                    $wallet = (new WalletRepository())->getWalletByCurrency($userId, $currency->id, false);

                    $creditedAmount = (new DepositCreditService())->credit($wallet, $amount);

                    try {
                        // Notify user
                        Mail::to($wallet->user)->queue(new DepositReceived($wallet->user, $creditedAmount, $currency->symbol));

                        // Admin Email Notification
                        $adminEmail = Setting::get('notification.admin_email', false);
                        $notificationAllowed = Setting::get('notification.crypto_deposits', false);

                        if ($adminEmail && $notificationAllowed) {
                            $route = route('admin.reports.deposits') . "?search=" . $depositId;
                            Mail::to($adminEmail)->queue(new AdminDepositReceived($amount, $currency->symbol, $route));
                        }
                        // END Admin Email Notification

                    } catch (\Exception $e) {
                        Log::error('BRC-20 Deposit Notify Email Exception');
                    }

                }

            }


        } catch (\Exception $e) {
            Log::info('BRC20 was not deposited. Exception:');
            Log::error($e);
        }
    }


    public function handleWithdraw($withdrawal, $type) {

        return DB::transaction(function () use ($withdrawal, $type) {

            $walletService = new WalletService();

            $wallet = (new WalletRepository())->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency->id);

            if ($withdrawal->status == WITHDRAWAL_CONFIRMED_BY_PROVIDER) {

                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                try {

                    // Notify user
                    Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed($withdrawal->user, $withdrawal->amount, $withdrawal->currency->symbol, $withdrawal->txn));


                } catch (\Exception $e) {

                }

            } elseif ($withdrawal->status < BNB_WITHDRAW_FAILED) {

                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                $walletService->increase($wallet, $withdrawal->amount);
            }

            event(new WithdrawalUpdated($withdrawal));

            return true;

        }, DB_REPEAT_AFTER_DEADLOCK);
    }
}
