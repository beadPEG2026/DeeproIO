<?php

namespace App\Services\Fireblocks;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Mail\Withdrawals\AdminWithdrawalReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Models\Deposit\Deposit;
use App\Models\Wallet\WalletAddress;
use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\Fireblocks\Types\DestinationTransferPeerPath;
use App\Services\Fireblocks\Types\Enums\PeerEnums;
use App\Services\Fireblocks\Types\TransferPeerPath;
use App\Services\PaymentGateways\Coin\Bitcoin\Api\OrdinalApiGateway;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class FireblocksService {

    private $_request = null;
    private $_symbol = null;
    private $_network = null;

    public function verifyCallback() {
        return true;
    }

    public function __construct() {

    }

    public function createAddress($user, $network, $symbol) {

        $fireblocks = new FireblocksSDK();

        // Create vault account
        if(!$user->fb_vault_name) {
            $result = $fireblocks->create_vault_account($user->email, false, $user->id, true);
            $user->fb_vault_name = $result['name'];
            $user->fb_vault_id = $result['id'];
            $user->update();
        }

        $fireblockNetworks = config('fireblocks.networks');

        if(!isset($fireblockNetworks[$network->id][$symbol])) {
            return false;
        }

        $result = $fireblocks->create_vault_asset($user->fb_vault_id, $fireblockNetworks[$network->id][$symbol]);

        return [
            'asset_id' => $result['id'],
            'address' => $result['address'],
            'dest_tag' => $result['tag'],
        ];
    }

    public function handleCallback() {

        // Request instance
        $this->_request = request();

        $data = $this->_request->get('data');

        if($data['operation'] !== "TRANSFER") return false;

        if($this->_request->get('type') == "TRANSACTION_STATUS_UPDATED" && $data['destination']['type'] == "ONE_TIME_ADDRESS" && $data['status'] == "COMPLETED") {
            return $this->handleWithdrawState();
        }

        if($data['destination']['type'] == "VAULT_ACCOUNT" && $data['destination']['id']) {
            return $this->handleDeposit();
        }

        return false;
    }

    public function handleDeposit() {

        return DB::transaction(function () {

            $type = $this->_request->get('type', null);
            $data = $this->_request->get('data');
            $txn = $data['txHash'];

            $operation = $data['operation'];
            $address = $data['destinationAddress'];
            $status = $data['status'];
            $networkName = $data['assetId'];
            $parsedNetwork = $this->getSymbolByNetworkName($networkName);

            if(!$txn || !$parsedNetwork || $operation !== "TRANSFER") return false;

            if($type !== "TRANSACTION_CREATED" && $type !== "TRANSACTION_STATUS_UPDATED") {
                return false;
            }

            $this->_symbol = $parsedNetwork['symbol'];
            $this->_network = $parsedNetwork['network'];
            $confirms = $data['numOfConfirmations'];

            $currency = (new CurrencyRepository())->getCurrencyBySymbol($this->_symbol, 'coin', false);

            if(!$currency) return false;

            $network = (new NetworkRepository())->getById($this->_network);

            $deposit = Deposit::where('txn', $txn)->where('network_id', $network->id)->first();
            $depositRepository = new DepositRepository();

            $walletAddress = WalletAddress::with('wallet.currency')->where('address', $address)->whereIn('network_id', [NETWORK_BEP, NETWORK_BNB, NETWORK_ETH, NETWORK_ERC, NETWORK_MATIC20, NETWORK_MATIC])->has('user')->first();

            $wallet = (new WalletRepository())->getWalletByCurrency($walletAddress->user_id, $currency->id, false);

            if($deposit) {

                if($deposit->status == DEPOSIT_PENDING && $status == "COMPLETED") {

                    $amount = math_sub($deposit->amount, $deposit->system_fee);

                    $deposit->status = DEPOSIT_CONFIRMED;
                    $deposit->confirms = intval($confirms);
                    $deposit->update();

                    $currencyWallet = (new WalletRepository())->getWalletByCurrency($wallet->user_id, $currency->id, false);

                    $creditedAmount = (new DepositCreditService())->credit($currencyWallet, $amount);

                    try {
                        // Notify user
                        Mail::to($wallet->user)->queue(new DepositReceived($wallet->user, $creditedAmount, $currency->symbol));

                        // Admin Email Notification
                        $adminEmail = Setting::get('notification.admin_email', false);
                        $notificationAllowed = Setting::get('notification.crypto_deposits', false);

                        if($adminEmail && $notificationAllowed) {
                            $route = route('admin.reports.deposits') . "?search=" . $deposit->deposit_id;
                            Mail::to($adminEmail)->queue(new AdminDepositReceived($deposit->amount, $currency->symbol, $route));
                        }
                        // END Admin Email Notification

                    } catch (\Exception $e) {
                        Log::error('Deposit Notify Email Exception');
                    }
                }

                return true;
            }

            $amount = $data['amount'];

            if(math_compare($amount, $currency->min_deposit) < 0) {
                $status = DEPOSIT_IGNORED;
            }

            $data = [
                'deposit_id' => generate_uuid(),
                'txn' => $txn,
                'source_id' => generate_string(),
                'currency_id' => $currency->id,
                'type' => 'coin',
                'network_id' => $network->id,
                'amount' => $amount,
                'full_amount' => $amount,
                'network_fee' => $data['networkFee'],
                'address' => $address,
                'user_id' => $wallet->user_id,
                'confirms' => $confirms,
                'status' => DEPOSIT_PENDING,
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

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    public function handleWithdraw($withdrawal, $amount) {

        return DB::transaction(function () use ($withdrawal, $amount) {

            $walletService = new WalletService();

            $wallet = (new WalletRepository())->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency->id);

            $fireblocksSDK = new FireblocksSDK();

            $sourcePeer = new TransferPeerPath(PeerEnums::VAULT_ACCOUNT(), config('fireblocks.hot_wallet_vault'));

            $address = (object) array('address' => $withdrawal->address, 'tag' => '');

            $destinationPeer = new DestinationTransferPeerPath(PeerEnums::ONE_TIME_ADDRESS(), null, $address);

            $response = $fireblocksSDK->create_transaction($this->getAliasBySymbol($withdrawal->currency->symbol), $amount, $sourcePeer, $destinationPeer, null, null, true);

            if(isset($response['id']) && $response['status'] == "SUBMITTED") {
                $withdrawal->status = WITHDRAWAL_WAITING_PROVIDER_APPROVAL;
                $withdrawal->source_id = $response['id'];
            } else {
                $withdrawal->status = WITHDRAWAL_FAILED;
            }

            $withdrawal->update();

            if ($withdrawal->initial_raw) {

                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');

                try {

                    // Notify user
                    Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed($withdrawal->user, $withdrawal->amount, $withdrawal->currency->symbol, $withdrawal->txn));

                    // Admin Email Notification
                    $adminEmail = Setting::get('notification.admin_email', false);
                    $notificationAllowed = Setting::get('notification.crypto_withdrawals', false);

                    if ($adminEmail && $notificationAllowed) {
                        $route = route('admin.reports.withdrawals') . "?search=" . $withdrawal->withdrawal_id;
                        Mail::to($adminEmail)->queue(new AdminWithdrawalReceived($withdrawal->amount, $withdrawal->currency->symbol, $route));
                    }
                    // END Admin Email Notification

                } catch (\Exception $e) {

                }

            } elseif ($withdrawal->status == BNB_WITHDRAW_FAILED) {
                $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                $walletService->increase($wallet, $withdrawal->amount);
            }

            event(new WithdrawalUpdated($withdrawal));

            return $response;

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    public function handleWithdrawState() {

        $data = $this->_request->get('data');
        $id = $data['id'];

        $withdrawal = Withdrawal::where('status', WITHDRAWAL_WAITING_PROVIDER_APPROVAL)->where('source_id', $id)->first();
        $withdrawal->txn = $data['txHash'];
        $withdrawal->status = WITHDRAWAL_CONFIRMED_BY_PROVIDER;
        $withdrawal->update();

        return true;
    }

    public function getSymbolByNetworkName($networkAlias) {

        $networks = config('fireblocks.networks');

        foreach ($networks as $networkId => $network) {
            foreach ($network as $symbolName => $networkName) {
                if($networkName == $networkAlias) {
                    return [
                        'symbol' => $symbolName,
                        'network' => $networkId
                    ];
                }
            }
        }

        return false;
    }

    public function getAliasBySymbol($symbol) {

        $networks = config('fireblocks.networks');

        foreach ($networks as $networkId => $network) {
            foreach ($network as $symbolName => $networkName) {
                if($symbolName == $symbol) {
                    return $networkName;
                }
            }
        }

        return false;
    }
}
