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
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class CustomBitcoinService {

    public $url;
    public $token;
    public $walletname;

    public function __construct()
    {
        $this->url = config('bitcoind.custom.url');
        $this->token = config('bitcoind.custom.token');
        $this->walletname = config('bitcoind.custom.walletname');
    }

    private $_request = null;
    private $_symbol = null;
    private $_network = null;

    public function verifyCallback() {
        return true;
    }

    public function handleCallback($symbol) {

        // Request instance
        $this->_request = request();

        $this->_symbol = $symbol;

        $this->_network = $symbol == "BTC" ? 'btc' : 'btc_fork';

        return $this->handleDeposit();
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

    public function getBalance()
    {
        $params = [
            'method' => "getwalletinfo",
            'params' => [],
            'wallet' => $this->walletname
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->url . '/api/wallet/request/node?token=' . $this->token, $params);

        $res = $response->json();

        return $res['data']['balance'];

    }

    public function getTransactions()
    {
        $params = [
            'method' => "listtransactions",
            'params' => ['*', 25],
            'wallet' => $this->walletname
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->url . '/api/wallet/request/node?token=' . $this->token, $params);

        $res = $response->json();

        return $res['data'];
    }
}
