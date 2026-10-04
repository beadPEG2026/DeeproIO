<?php

namespace App\Services\PaymentGateways\Coin\Ripple\Services;

use App\Events\DepositUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Deposit\Deposit;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use XRPL\Client\XRPLClient;
use XRPL\Service\Wallet\WalletGenerator;
use XRPL\ValueObject\Wallet;
use XRPL\Enum\Algorithm;
use Setting;
use XRPL\Helper\XRPConverter;

class RippleService
{
    protected $client;
    protected $endpoint;

    public function __construct()
    {
        $this->endpoint = config('ripple.rpc_endpoint');
        $this->client = new XRPLClient($this->endpoint);
    }

    public function getSystemWallet()
    {
        return ['address' => Setting::get('ripple.wallet')];
    }

    public function getBalance($address)
    {
        $response = $this->client->account->getAccountInfo($address);

        $balanceDrops = $response->accountData->balance ?? null;

        if ($balanceDrops !== null) {
            return ['balance' => $balanceDrops / 1000000];
        } else {
            return ['balance' => 0];
        }
    }

    public function generateWallet()
    {
        $wallet = WalletGenerator::generate(Algorithm::SECP256K1);

        $address = $wallet->getAddress();
        $seed = $wallet->getSeedString();

        return ['address' => $address, 'seed' => $seed];
    }

    public function generateMemo()
    {
        return str_pad(random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    public function isValidXrpAddress($address)
    {
        return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $address) === 1;
    }

    public function getTransactions($address)
    {
        $response = $this->client->account->getAccountTransactions($address, 'Payment', null, null, null, null, false, 50);
        $deposits = [];
        $transactions = $response->transactions ?? [];

        foreach ($transactions as $txn) {

            $transaction = $txn->transaction;

            if($txn->meta->transactionResult !== "tesSUCCESS") continue;

            if (
                $transaction->transactionType === 'Payment' &&
                $transaction->destination === $address
            ) {
                $amount = XRPConverter::dropsToXrp($transaction->amount);
                $dropAmount = $transaction->amount->getValue();
                $sender = $transaction->account;
                $hash = $transaction->hash;

                if($transaction->destinationTag && !$this->signatureExists($address, $hash)) {
                    $deposits[] = [
                        'from' => $sender,
                        'destination' => $address,
                        'amount' => $amount,
                        'full_amount' => $dropAmount,
                        'tx_hash' => $hash,
                        'memo' => $transaction->destinationTag,
                    ];
                }
            }

        }

        return $deposits;
    }

    public function storeXrpDeposit($transaction)
    {
        $deposit = Deposit::where('txn', $transaction['tx_hash'])->where('network_id', NETWORK_RIPPLE)->first();

        $walletAddress = WalletAddress::with(['wallet', 'user'])->where('network_id', NETWORK_RIPPLE)->where('payment_id', $transaction['memo'])->first();

        // Deposit exists
        if (!$deposit && $walletAddress) {

            $symbol = 'XRP';
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
                'txn' => $transaction['tx_hash'],
                'source_id' => generate_string(),
                'currency_id' => $currency->id,
                'type' => 'coin',
                'network_id' => $network->id,
                'amount' => $amount,
                'full_amount' => $transaction['full_amount'],
                'network_fee' => 0,
                'address' => $transaction['destination'],
                'user_id' => $walletAddress->user_id,
                'confirms' => 1,
                'status' => $status,
                'wallet_transfer_status' => $status == DEPOSIT_CONFIRMED ? 'processed' : null,
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

            $currencyWallet = (new WalletRepository())->getWalletByCurrency($walletAddress->user_id, $currency->id, false);

            $creditedAmount = (new DepositCreditService())->credit($currencyWallet, $amount);

            $this->storeSignature($walletAddress->address, $transaction['tx_hash']);

            try {
                // Notify user
                Mail::to($walletAddress->user)->queue(new DepositReceived($walletAddress->user, $creditedAmount, $currency->symbol));

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

    /**
     * Used to generate data to withdraw
     * @param $withdrawal_id
     * @param $address
     * @param $amount
     * @return array
     */
    public function withdraw($withdrawal_id, $address, $amount, $paymentId)
    {
        $pk = Setting::get('ripple.private_key');
        $wallet = Wallet::generateFromSeed($pk);

        $source = '';
        $message = generate_uuid();


        $transactionData = [
            'TransactionType' => 'Payment',
            'Account' => $wallet->getAddress(),
            'Destination' => $address,
            'Amount' => XRPConverter::xrpToDrops($amount),

        ];

        if($paymentId) {
            $transactionData['DestinationTag'] = (int)$paymentId;
        }

        $response = $this->client->submitSingleSignTransaction($transactionData, $wallet);

        if(!$response) {
            $status = STATUS_VALIDATION_ERROR;
        } else {
            $source = $message;
            $status = STATUS_OK;
        }

        return [
            'status' => $status,
            'source' => $source,
            'message' => $message,
            'txn' => $response,
        ];
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

    public function ping()
    {
        return ['address' => Setting::get('ripple.wallet')];
    }
}
