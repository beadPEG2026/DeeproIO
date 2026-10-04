<?php

namespace App\Services\PaymentGateways\Fiat\Stripe\Services;

use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Fiat\Stripe\Model\StripeModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Setting;
use Stripe\Event;
use Stripe\Product;
use Stripe\Stripe;

class StripeService {

    public function verifyCallback() {

        $payload = @file_get_contents('php://input');

        try {
            $event = Event::constructFrom(
                json_decode($payload, true)
            );

            Log::info($payload);

        } catch(\UnexpectedValueException $e) {
            Log::error($e);
            return false;
        }

        Log::error($event);

        // Handle the event
        switch ($event->type) {
            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($event->data->object);
            default:
                Log::error('Received unknown event type ' . $event->type);
                return false;
        }
    }

    public function handlePaymentIntentSucceeded($paymentIntent) {

        if(!isset($paymentIntent->id)) {
            Log::error('Payment Intent not valid');
            return false;
        }

        $model = StripeModel::where('intent_id', $paymentIntent->id)->pending()->first();

        if(!$model) {
            Log::error('Pending intent with id ' . $paymentIntent->id . ' not found');
            return false;
        }

        return DB::transaction(function () use ($model, $paymentIntent) {

            $walletRepository = new WalletRepository();
            $depositCreditService = new DepositCreditService();
            $currencyRepository = new CurrencyRepository();
            $currencyService = new CurrencyService();

            $model->status = "confirmed";
            $model->amount = $paymentIntent->amount_received;
            $model->currency_raw = $paymentIntent->currency;
            $model->update();

            $currency = $currencyRepository->get($model->currency_id);

            $baseCurrency = mb_strtolower(setting('stripe.currency', 'usd'));

            $amountInBaseCurrency = $paymentIntent->amount_received;
            $zeroCurrencies = config('stripe.zero_currencies');

            if(!in_array(mb_strtoupper($baseCurrency), $zeroCurrencies)) {
                $amountInBaseCurrency = math_divide($amountInBaseCurrency, 100);
            }

            $actualAmount = math_divide($amountInBaseCurrency, $currency->cc_exchange_rate);

            $currencyService->increase($currency, $actualAmount);
            $currency->wallet_balance_updated_at = Carbon::now();
            $currency->update();

            if($currency->deposit_fee > 0) {
                $fee = math_percentage($actualAmount, $currency->deposit_fee);
            } else {
                $fee = $currency->deposit_fee_fixed;
            }

            $amountWithFee = math_sub($actualAmount, $fee);

            $depositId = generate_uuid();

            $data = [
              'deposit_id' => $depositId,
              'type' => 'cc',
              'user_id' => $model->user_id,
              'currency_id' => $model->currency_id,
              'amount' => $actualAmount,
              'fee' => $fee,
              'status' => FIAT_DEPOSIT_CONFIRMED,
              'approved_at' => Carbon::now()
            ];

            $storedDeposit = (new FiatDepositRepository())->store($data);

            // Increase user wallet
            $wallet = $walletRepository->getWalletByCurrency($model->user_id, $model->currency_id);
            $creditedAmount = $depositCreditService->credit($wallet, $amountWithFee);

            Mail::to($storedDeposit->user)->queue(new DepositReceived($storedDeposit->user, math_formatter($creditedAmount, $storedDeposit->currency->decimals), $storedDeposit->currency->symbol));

            // Admin Email Notification
            $adminEmail = Setting::get('notification.admin_email', false);
            $notificationAllowed = Setting::get('notification.fiat_deposits', false);

            if($adminEmail && $notificationAllowed) {
                $route = route('admin.reports.deposits.fiat') . "?search=" . $depositId;
                Mail::to($adminEmail)->queue(new AdminDepositReceived(math_formatter($amountWithFee, $storedDeposit->currency->decimals), $storedDeposit->currency->symbol, $route));
            }
            // END Admin Email Notification

            return true;

        }, DB_REPEAT_AFTER_DEADLOCK);

    }

    public function ping() {

        Stripe::setApiKey(setting('stripe.secret_key'));
        $products = Product::all();

        return $products->all();
    }
}
