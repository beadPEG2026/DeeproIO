<?php

namespace App\Console\Commands\Customtoken;

use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Deposit\Deposit;
use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Blockchain\TokenDecimalsService;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Coin\Customtoken\Services\CustomtokenService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class MonitorTokenDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customtoken:handle-token-deposit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public $depositRepository;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->depositRepository = new DepositRepository();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if(Network::where('id', NETWORK_CUSTOMTOKEN_TOKEN)->where('deposit_status', false)->count()) {
            return false;
        }

        $wallets = WalletAddress::with(['wallet.currency', 'user'])->whereIn('network_id', [NETWORK_CUSTOMTOKEN_TOKEN])->has('user')->orderByDesc('created_at')->get();

        foreach ($wallets as $wallet) {

            if(config('app.readonly') && !$wallet->user->hasRole('admin')) {
                continue;
            }

            $this->check($wallet);

            usleep(500000);
        }
    }

    public function check($wallet, $currency = false) {

        $response = Http::get(env('APP_CUSTOMTOKEN_API', 'https://explorer.customtoken.xyz/api/v2/addresses/' . $wallet->address . '/token-transfers'), [
            'filter' => 'to',
            'type' => 'ERC-20'
        ]);

        $data = $response->json();

        if($response->successful() && isset($data['items'])) {

            foreach ($data['items'] as $transaction) {

                if(!$transaction['tx_hash']) continue;

                if(!isset($transaction['to']['hash']) || mb_strtolower($transaction['to']['hash']) !== mb_strtolower($wallet->address)) continue;

                $currency = (new CurrencyRepository())->getCurrencyByContract($transaction['token']['address']);

                if (!$currency || in_array(NETWORK_CUSTOMTOKEN_TOKEN, $currency->disabled_deposit_networks)) continue;

                $deposit = Deposit::where('txn', $transaction['tx_hash'])->where('network_id', NETWORK_CUSTOMTOKEN_TOKEN)->first();

                // Deposit exists
                if($deposit) {
                    // IMPORTANT: Add Deposit Confirmation Checker
                    //if($deposit->status == DEPOSIT_PENDING && (int)$confirmations >= $currency->min_deposit_confirmation) {
                    if($deposit->status == DEPOSIT_PENDING_TRANSFER && $deposit->wallet_transfer_status == "processed") {

                        $amount = math_sub($deposit->amount, $deposit->system_fee);

                        $deposit->status = DEPOSIT_CONFIRMED;
                        $deposit->confirms = intval(0);
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

                    continue;

                }

                $service = new CustomtokenService();
                $amountDivisor = (new TokenDecimalsService())->divisor(
                    NETWORK_CUSTOMTOKEN_TOKEN,
                    $transaction['token']['address'] ?? null,
                    $transaction['total']['decimals'] ?? null,
                    (int) ($currency->decimals ?? 18)
                );

                $data = [
                    'user_id' => $wallet->user_id,
                    'symbol' => $currency->symbol,
                    'hash' => $transaction['tx_hash'],
                    'deposit_id' => generate_string(),
                    'fee' => 0,
                    'address' => mb_strtolower($transaction['to']['hash']),
                    'contract' => $transaction['token']['address'],
                    'confirms' => 0,
                    'amount' => math_divide($transaction['total']['value'], $amountDivisor),
                    'full_amount' => $transaction['total']['value']
                ];

                $service->handleDeposit($data);

            }

        }

        if(!$response->successful()) {
            Log::error('Custom Network Exception:');
            Log::error($response->body());
        }
    }
}
