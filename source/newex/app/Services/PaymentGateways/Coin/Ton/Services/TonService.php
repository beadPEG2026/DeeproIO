<?php

namespace App\Services\PaymentGateways\Coin\Ton\Services;

use App\Events\DepositUpdated;
use App\Events\WithdrawalUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Mail\Withdrawals\WithdrawalConfirmed;
use App\Models\Deposit\Deposit;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Services\PaymentGateways\Coin\Ton\Api\TonGateway;
use Setting;

class TonService
{
    protected $endpoint;
    protected $apiKey;

    public function __construct()
    {
        $this->endpoint = config('ton.api_endpoint');
        $this->apiKey = config('ton.api_key');
    }

    public function getSystemWallet()
    {
        return ['address' => Setting::get('ton.wallet')];
    }

    public function generateWallet()
    {
        // Use Node bridge to generate system wallet like Solana
        $gateway = new TonGateway();
        $wallet = $gateway->createTonAddress();
        if ($wallet && isset($wallet['address'])) {
            return ['address' => $wallet['address'], 'seed' => $wallet['private_key'] ?? ''];
        }
        // fallback to existing settings if bridge not available
        $address = Setting::get('ton.wallet');
        $seed = Setting::get('ton.private_key');
        return ['address' => $address ?: 'TON_WALLET_NOT_SET', 'seed' => $seed ?? ''];
    }

    public function generateMemo()
    {
        // Many exchanges use numeric comment/memo for TON
        return str_pad(random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    public function isValidTonAddress($address)
    {
        if (!is_string($address) || $address === '') {
            return false;
        }
        // Prefer Node bridge validation (uses @ton/ton Address.parse)
        try {
            $gateway = new TonGateway();
            $valid = $gateway->validateAddress($address);
            if ($valid) return true;
        } catch (\Exception $e) {
            // fall through to local checks
        }
        // Fallback: basic TON-friendly address checks
        // Accept user-friendly base64url with checksum, often starts with EQ/0Q/kQ/UQ etc., length typically 48-55
        $len = strlen($address);
        if ($len < 36 || $len > 66) return false;
        // Basic base64url charset check (no +, /)
        if (!preg_match('/^[A-Za-z0-9_:+-]+$/', $address)) { // include possible ':' and '-' for addr strings
            return false;
        }
        return true;
    }

    public function getTransactions($address)
    {
        try {
            if (!$address) return [];
            $gateway = new TonGateway();
            $list = $gateway->getTransactions($address);
            if (!is_array($list)) return [];

            $deposits = [];
            foreach ($list as $tx) {
                // Expect normalized items from bridge
                $to = $tx['destination'] ?? null;
                $from = $tx['from'] ?? null;
                $value = $tx['full_amount'] ?? null;
                $hash = $tx['tx_hash'] ?? null;
                $memo = $tx['memo'] ?? null;
                $amount = $tx['amount'] ?? null;

                if ($to === $address && $hash && $memo && !$this->signatureExists($address, $hash)) {
                    // Ensure amount is a decimal string/float representing TON
                    if ($amount === null && $value !== null) {
                        $amount = bcdiv((string)$value, '1000000000', 9);
                    }
                    $deposits[] = [
                        'from' => $from,
                        'destination' => $to,
                        'amount' => (string)$amount,
                        'full_amount' => (string)$value,
                        'tx_hash' => $hash,
                        'memo' => $memo,
                    ];
                }
            }
            return $deposits;
        } catch (\Exception $e) {
            Log::error($e);
            return [];
        }
    }

    public function storeTonDeposit($transaction)
    {
        $deposit = Deposit::where('txn', $transaction['tx_hash'])->where('network_id', NETWORK_TON)->first();

        $walletAddress = WalletAddress::with(['wallet', 'user'])->where('network_id', NETWORK_TON)->where('payment_id', $transaction['memo'])->first();

        if (!$deposit && $walletAddress) {

            $symbol = 'TON';
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

            $amountCredit = math_sub($deposit->amount, $deposit->system_fee);
            $currencyWallet = (new WalletRepository())->getWalletByCurrency($walletAddress->user_id, $currency->id, false);
            $creditedAmount = (new DepositCreditService())->credit($currencyWallet, $amountCredit);

            $this->storeSignature($walletAddress->address, $transaction['tx_hash']);

            try {
                Mail::to($walletAddress->user)->queue(new DepositReceived($walletAddress->user, $creditedAmount, $currency->symbol));
                $adminEmail = Setting::get('notification.admin_email', false);
                $notificationAllowed = Setting::get('notification.crypto_deposits', false);
                if ($adminEmail && $notificationAllowed) {
                    $route = route('admin.reports.deposits') . "?search=" . $deposit->deposit_id;
                    Mail::to($adminEmail)->queue(new AdminDepositReceived($deposit->amount, $currency->symbol, $route));
                }
            } catch (\Exception $e) {
                Log::error('TON Deposit Notify Email Exception');
            }
        }
    }

    public function withdraw($withdrawal_id, $address, $amount, $paymentId)
    {
        $gateway = new TonGateway();
        return $gateway->withdraw($withdrawal_id, $address, $amount, $paymentId);
    }

    /**
     * Handle withdrawal status and balance updates
     * Called by HandlePendingTonWithdrawalsCommand after bridge returns tx hash
     */
    public function handleWithdraw($withdrawal)
    {
        return DB::transaction(function () use ($withdrawal) {

            $walletService = new WalletService();
            $wallet = (new WalletRepository())->getWalletByCurrency($withdrawal->user_id, $withdrawal->currency->id);

            if ($withdrawal->status == WITHDRAWAL_CONFIRMED_BY_PROVIDER) {

                if ($withdrawal->source_id != "system") {
                    $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                }

                try {
                    if ($withdrawal->source_id != "system") {
                        // Notify user
                        Mail::to($withdrawal->user)->queue(new WithdrawalConfirmed(
                            $withdrawal->user,
                            $withdrawal->amount,
                            $withdrawal->currency->symbol,
                            $withdrawal->txn
                        ));
                    }
                } catch (\Exception $e) {
                    Log::error('TON withdrawal email notification failed', [
                        'withdrawal_id' => $withdrawal->id,
                        'error' => $e->getMessage(),
                    ]);
                }

            } elseif ($withdrawal->status == WITHDRAWAL_FAILED) {
                // Refund on failure
                if ($withdrawal->source_id != "system") {
                    $walletService->decrease($wallet, $withdrawal->amount, 'withdraw');
                    $walletService->increase($wallet, $withdrawal->amount);
                }
            }

            event(new WithdrawalUpdated($withdrawal));

            return true;

        }, DB_REPEAT_AFTER_DEADLOCK);
    }

    function storeSignature(string $walletAddress, string $signature)
    {
        $basePath = storage_path('app/signatures');
        $walletDir = $basePath;
        if (!File::exists($walletDir)) {
            File::makeDirectory($walletDir, 0755, true);
        }
        $signatureFile = $walletDir . '/' . $walletAddress;
        if (File::exists($signatureFile)) {
            File::append($signatureFile, $signature . PHP_EOL);
            return true;
        }
        File::put($signatureFile, $signature . PHP_EOL);
        return true;
    }

    function signatureExists(string $walletAddress, string $signature)
    {
        $filePath = storage_path('app/signatures/' . $walletAddress);
        if (!File::exists($filePath)) {
            return false;
        }
        $lines = File::lines($filePath)->toArray();
        return in_array($signature, array_map('trim', $lines));
    }
}
