<?php

namespace App\Console\Commands\Customtoken;

use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Deposit\Deposit;
use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Network\NetworkRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Coin\Customtoken\Api\CustomtokenGateway;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Setting;

class HandleDeposit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:handle-deposit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public $customtokenGateway;
    public $depositRepository;

    public function __construct()
    {
        parent::__construct();

        $this->customtokenGateway = new CustomtokenGateway();
        $this->depositRepository = new DepositRepository();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if(Network::where('id', NETWORK_CUSTOMTOKEN_NETWORK)->where('deposit_status', false)->count()) {
            return false;
        }

        Log::info("message", ["message" => "Handle Deposit Command Started"]);

        // Fetch wallets with the necessary relationships
        $wallets = WalletAddress::with('wallet.currency')->whereIn('network_id', [NETWORK_CUSTOMTOKEN_NETWORK])->has('user')->orderByDesc('created_at')->get();

        $currency = (new CurrencyRepository())->getCurrencyBySymbol('CUSTOMTOKEN');

        if (!$currency || in_array(NETWORK_CUSTOMTOKEN_NETWORK, $currency->disabled_deposit_networks)) {
            Log::info("message", ["message" => "Currency CUSTOMTOKEN not found or network disabled"]);
            return;
        }

        // Loop through each wallet to check for transactions
        foreach ($wallets as $wallet) {
            $this->check($wallet, $currency);
        }
    }

    public function check($wallet, $currency = false) {

        $transactions = $this->customtokenGateway->getReceivedTransactions($wallet->address);

        // Log transactions if they are empty or missing the 'items' key
        if (!$transactions || !isset($transactions['items'])) {
            return;
        }

        // Loop through the transactions
        foreach ($transactions['items'] as $transaction) {

            // Check if the deposit already exists
            $deposit =  Deposit::where('network_id', NETWORK_CUSTOMTOKEN_NETWORK)->where(function($query) use ($transaction) {
                $query->where('txn', Str::lower($transaction['hash']))->orWhere('txn', $transaction['hash']);
            })->first();

            if ($deposit) {

                if($deposit->status == DEPOSIT_PENDING_TRANSFER && $deposit->wallet_transfer_status == "processed") {

                    $deposit->status = DEPOSIT_CONFIRMED;
                    $deposit->save();

                    // Update wallet balance
                    $currencyWallet = (new WalletRepository())->getWalletByCurrency($deposit->user_id, $currency->id, false);
                    $creditedAmount = (new DepositCreditService())->credit($currencyWallet, $deposit->amount);

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

                continue;
            }

            // Check if the transaction is for the correct wallet address
            if (Str::lower($transaction['to']['hash']) != Str::lower($wallet->address)) {
                Log::info("message", [
                    "message" => "Transaction does not match wallet address",
                    "txn" => Str::lower($transaction['hash']),
                    "wallet_address" => $wallet->address
                ]);
                continue;
            }

            // Fetch network details
            $network = (new NetworkRepository())->getIdBySlug('customtoken');

            // Prepare deposit data
            $depositData = [
                'user_id' => $wallet->user_id,
                'txn' => Str::lower($transaction['hash']),
                'deposit_id' => generate_uuid(),
                'source_id' => generate_string(),
                'fee' => 0,
                'address' => Str::lower($transaction['to']['hash']),
                'amount' => math_divide($transaction['value'], bcpow('10', '18', 0)),
                'full_amount' => $transaction['value'],
                'currency_id' => $currency->id,
                'type' => 'coin',
                'network_id' => $network->id,
                'confirms' => $transaction['confirmations'] ?? 0,
                'status' => DEPOSIT_PENDING_TRANSFER,
                'initial_raw' => json_encode($transaction),
            ];

            Log::info("message", ["message" => "Deposit Data", "depositData" => $depositData]);

            // Process the deposit transaction
            DB::transaction(function () use ($depositData, $currency) {

                $status = DEPOSIT_CONFIRMED;

                // Check if the amount is greater than or equal to the minimum deposit amount
                if (math_compare($depositData['amount'], $currency->min_deposit) < 0) {
                    $status = DEPOSIT_IGNORED;
                }

                // Store the deposit record
                $deposited = $this->depositRepository->store($depositData);

                // If the deposit amount is below the minimum, mark as ignored
                if ($status == DEPOSIT_IGNORED) {
                    $deposited->status = DEPOSIT_IGNORED;
                    $deposited->save();
                }

                // Mark deposit as processed
                $deposited->wallet_transfer_status = 'pending';
                $deposited->save();

                return true;
            }, DB_REPEAT_AFTER_DEADLOCK);

            // Sleep for 1 second between each transaction processing
            sleep(1);
        }
    }
}
