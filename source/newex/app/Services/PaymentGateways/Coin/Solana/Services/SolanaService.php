<?php

namespace App\Services\PaymentGateways\Coin\Solana\Services;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Models\Currency\Currency;
use App\Models\Deposit\Deposit;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\Wallet\WalletService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class SolanaService
{
    protected $client;
    protected $endpoint;

    public function __construct()
    {
        $this->endpoint = config('solana.rpc_endpoint');
        $this->client = new Client([
            'base_uri' => $this->endpoint,
            'timeout'  => 10.0,
        ]);
    }

    public function getSignaturesForAddress(string $address, int $limit = 10)
    {
        $response = $this->client->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getSignaturesForAddress',
                'params'  => [$address, ['limit' => $limit, 'commitment' => 'finalized']],
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true)['result'] ?? [];
    }

    public function getTransaction(string $signature)
    {
        $response = $this->client->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getTransaction',
                'params'  => [$signature, ['encoding' => 'json']],
            ]
        ]);
        return json_decode($response->getBody()->getContents(), true)['result'] ?? null;
    }

    public function getParsedTransaction(string $signature)
    {
        $response = $this->client->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getTransaction',
                'params'  => [$signature, ["encoding" => "jsonParsed", 'commitment' => 'finalized']],
            ]
        ]);
        return json_decode($response->getBody()->getContents(), true)['result'] ?? null;
    }

    public function getSolTransfers(string $address, int $limit = 20, $modelClass = null)
    {
        $sigs = $this->getSignaturesForAddress($address, $limit);
        $transfers = [];

        foreach ($sigs as $key=>$sig) {

            if($this->signatureExists($address, $sig['signature'])) continue;

            $tx = $this->getParsedTransaction($sig['signature']);

            $transfer = null;

            foreach ($tx['transaction']['message']['instructions'] as $instruction) {

                if(!isset($instruction['parsed'])) continue;

                if($instruction['parsed']['type'] !== 'transfer' && $instruction['parsed']['type'] !== "transferChecked") {
                    continue;
                }

                $transferType = $instruction['parsed']['type'];
                $transfer = $instruction['parsed']['info'];
            }

            if(!$transfer) continue;

            if (!$tx || $transfer['source'] == $address) continue;
            if ($transfer['source'] == setting('solana.wallet')) continue;

            // Check for possible token account creation
            if($transferType == 'transferChecked') {

                // Mint Address Found
                $mintAddress = $transfer['mint'];

                if(Currency::where('sol_contract', $mintAddress)->exists()) {

                    if($modelClass) {
                        $model = $modelClass->where('address', $address)->first();
                    } else {
                        $model = WalletAddress::where('address', $address)->first();
                    }


                    $model->token_account = $transfer['destination'];
                    $model->save();

                }

                continue;
            }

            // Check if incoming deposit
            $transferedAmount = $transfer['lamports'];

            if($transferedAmount <= 0) continue;

            // System Program transfer
            $transfers[] = [
                'signature' => $sig['signature'],
                'amount' => $this->lamportsToSol($transferedAmount),
                'amount_in_lamports' => $transferedAmount,
                'sourceWallet' => $transfer['source'],
                'destinationWallet' => $transfer['destination'],
            ];
        }

        return $transfers;
    }

    public function getSplTransfers($address, $mint, $account, $limit = 20)
    {
        $sigs = $this->getSignaturesForAddress($account, $limit);

        $transfers = [];

        foreach ($sigs as $sig) {

            if($this->signatureExists($address, $sig['signature'])) continue;

            $tx = $this->getParsedTransaction($sig['signature']);

            $transfer = null;

            foreach ($tx['transaction']['message']['instructions'] as $instruction) {

                if(!isset($instruction['parsed'])) continue;

                if($instruction['parsed']['type'] !== 'transfer' && $instruction['parsed']['type'] !== "transferChecked") {
                    continue;
                }

                $transferType = $instruction['parsed']['type'];
                $transfer = $instruction['parsed']['info'];
            }

            if(!$transfer) continue;

            if (!$tx || $transfer['source'] == $address) continue;
            //if ($transfer['source'] == setting('solana.wallet')) continue;

            if(!isset($transfer['tokenAmount'])) continue;

            // Check if incoming deposit
            $transferedAmount = $transfer['tokenAmount'];

            if($transferedAmount <= 0) continue;

            // System Program transfer
            $transfers[] = [
                'signature' => $sig['signature'],
                'amount' => $transferedAmount['uiAmount'],
                'amount_in_lamports' => $transferedAmount['amount'],
                'sourceWallet' => $transfer['source'],
                'destinationWallet' => $transferType == "transfer" ? $transfer['destination'] : $address,
            ];
        }

        return $transfers;
    }

    public function lamportsToSol(int $lamports): float
    {
        return math_divide($lamports, 1000000000);
    }

    public function storeSolDeposit($wallet, $transaction)
    {
        $deposit = Deposit::where('txn', $transaction['signature'])->where('network_id', NETWORK_SOL)->first();

        // Deposit exists
        if (!$deposit) {

            $symbol = 'SOL';
            $networkSymbol = mb_strtolower($symbol);

            $depositRepository = new DepositRepository();

            $network = (new NetworkRepository())->getIdBySlug($networkSymbol);

            $amount = $transaction['amount'];

            $status = DEPOSIT_CONFIRMED;

            $currency = (new CurrencyRepository())->getCurrencyBySymbol($symbol, 'coin', false);

            if(!$currency) return false;

            if(math_compare($amount, $currency->min_deposit) < 0) {
                $status = DEPOSIT_IGNORED;
            }
            $data = [
                'deposit_id' => generate_uuid(),
                'txn' => $transaction['signature'],
                'source_id' => generate_string(),
                'currency_id' => $currency->id,
                'type' => 'coin',
                'network_id' => $network->id,
                'amount' => $amount,
                'full_amount' => $transaction['amount_in_lamports'],
                'network_fee' => 0,
                'address' => $wallet->address,
                'user_id' => $wallet->user_id,
                'confirms' => 1,
                'status' => $status,
                'initial_raw' => null,
                'system_fee' => (new CurrencyService())->calculateSystemFee($network->slug, $currency, $amount),
            ];

            $storedDeposit = $depositRepository->store($data);

            if(math_compare($amount, $currency->min_deposit) < 0) {
                return true;
            }

            $deposit = $depositRepository->getDeposit($storedDeposit->id);

            if($status != DEPOSIT_IGNORED) {
                event(new DepositUpdated($deposit->fresh(), 'received'));
            }

            $amount = math_sub($deposit->amount, $deposit->system_fee);

            $currencyWallet = (new WalletRepository())->getWalletByCurrency($wallet->user_id, $currency->id, false);

            $creditedAmount = (new DepositCreditService())->credit($currencyWallet, $amount);

            $this->storeSignature($wallet->address, $transaction['signature']);

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

    public function storeSplDeposit($wallet, $currency, $transaction)
    {
        $deposit = Deposit::where('txn', $transaction['signature'])->where('network_id', NETWORK_SOL_SPL)->first();

        // Deposit exists
        if (!$deposit) {

            $depositRepository = new DepositRepository();

            $network = (new NetworkRepository())->getIdBySlug('solspl');

            $amount = $transaction['amount'];

            $status = DEPOSIT_CONFIRMED;

            if(!$currency) return false;

            if(math_compare($amount, $currency->min_deposit) < 0) {
                $status = DEPOSIT_IGNORED;
            }

            $data = [
                'deposit_id' => generate_uuid(),
                'txn' => $transaction['signature'],
                'source_id' => generate_string(),
                'currency_id' => $currency->id,
                'type' => 'coin',
                'network_id' => $network->id,
                'amount' => $amount,
                'full_amount' => $transaction['amount_in_lamports'],
                'network_fee' => 0,
                'address' => $wallet->address,
                'user_id' => $wallet->user_id,
                'confirms' => 1,
                'status' => $status,
                'initial_raw' => null,
                'system_fee' => (new CurrencyService())->calculateSystemFee($network->slug, $currency, $amount),
            ];

            $storedDeposit = $depositRepository->store($data);

            if(math_compare($amount, $currency->min_deposit) < 0) {
                return true;
            }

            $deposit = $depositRepository->getDeposit($storedDeposit->id);

            if($status != DEPOSIT_IGNORED) {
                event(new DepositUpdated($deposit->fresh(), 'received'));
            }

            $amount = math_sub($deposit->amount, $deposit->system_fee);

            $currencyWallet = (new WalletRepository())->getWalletByCurrency($wallet->user_id, $currency->id, false);

            $creditedAmount = (new DepositCreditService())->credit($currencyWallet, $amount);

            $this->storeSignature($wallet->address, $transaction['signature']);

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

    function storeSignature(string $walletAddress, string $signature)
    {
        $basePath = storage_path('app/signatures');
        $walletDir = $basePath;

        // Create directory if it doesn't exist
        if (!File::exists($walletDir)) {
            File::makeDirectory($walletDir, 0755, true);
        }

        // Signature file path
        $signatureFile = $walletDir . '/' . $walletAddress;

        // Append signature to file as a new line
        if (File::exists($signatureFile)) {
            File::append($signatureFile, $signature . PHP_EOL);
            return true;
        }

        // Store signature as an empty file
        File::put($signatureFile, $signature . PHP_EOL);

        return true;
    }

    function signatureExists(string $walletAddress, string $signature)
    {
        $filePath = storage_path('app/signatures/' . $walletAddress);

        // If the file doesn't exist, the signature can't exist
        if (!File::exists($filePath)) {
            return false;
        }

        // Read all lines and check for the signature
        $lines = File::lines($filePath)->toArray();

        return in_array($signature, array_map('trim', $lines));
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

            } elseif ($withdrawal->status < SOLANA_WITHDRAW_FAILED) {

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
