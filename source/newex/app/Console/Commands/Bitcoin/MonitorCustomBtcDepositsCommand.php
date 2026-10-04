<?php

namespace App\Console\Commands\Bitcoin;

use App\Events\DepositUpdated;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Deposit\Deposit;
use App\Models\Network\Network;
use App\Models\Wallet\WalletAddress;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Coin\Bitcoin\Services\CustomBitcoinService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;

class MonitorCustomBtcDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'btc:monitor-custom-btc-deposits';

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
        if(Network::where('id', NETWORK_BTC)->where('deposit_status', false)->count()) {
            return false;
        }

        $currency = (new CurrencyRepository())->getCurrencyBySymbol('BTC');

        if (!$currency || in_array(NETWORK_BTC, $currency->disabled_deposit_networks)) return;

        $this->check($currency);
    }

    public function check($currency = false) {

        $service = new CustomBitcoinService();

        $txns = $service->getTransactions();

        foreach ($txns as $tx) {

            if(intval($tx['confirmations']) >= $currency->min_deposit_confirmation) {

                    $shouldBeIgnored = false;

                    $txn = $tx['txid'];

                    if(!$txn) return false;

                    $confirmations = intval($tx['confirmations']);

                    $network = Network::where('id', NETWORK_BTC)->first();

                    $depositRepository = new DepositRepository();

                    $deposit = $depositRepository->getByTxn($txn, NETWORK_BTC);

                    $address = $tx['address'];

                    $detectAddress = (new WalletRepository())->getWalletByAddress($address, null, NETWORK_BTC, false);

                    if (!$detectAddress) {
                        continue;
                    }

                    $wallet = (new WalletRepository())->getWalletByCurrency($detectAddress->user_id, $currency->id, false);

                    if (!$wallet) {
                        continue;
                    }

                    $amount = number_format($tx['amount'], 18);

                    $status = DEPOSIT_CONFIRMED;

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
                            'network_id' => NETWORK_BTC,
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
                        } else {
                            // Deposits below the minimum are recorded for audit only.
                            continue;
                        }

                        $amount = math_sub($deposit->amount, $deposit->system_fee);
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
                            Log::error('Custom BTC Deposit Notify Email Exception');
                        }
                    }

            }

        }


    }
}
