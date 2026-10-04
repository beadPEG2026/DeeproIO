<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Currency\Currency;
use App\Models\Deposit\FiatDeposit;
use App\Models\User\User;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Deposit\DepositCreditService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Setting;

class PerfectMoneyController extends Controller
{
    /**
     * Store the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function ipn()
    {
        Log::info('PM: entered');

        $altHash = strtoupper(md5(config('perfectmoney.hash')));

        $string =
            request()->get('PAYMENT_ID') . ':' . request()->get('PAYEE_ACCOUNT') . ':' .
            request()->get('PAYMENT_AMOUNT') . ':' . request()->get('PAYMENT_UNITS') . ':' .
            request()->get('PAYMENT_BATCH_NUM') . ':' .
            request()->get('PAYER_ACCOUNT') . ':' . $altHash . ':' .
            request()->get('TIMESTAMPGMT');

        // Note: Hash string not logged for security

        $hash = strtoupper(md5($string));

        // Note: Hashes not logged for security

        if ($hash === request()->get('V2_HASH')) {

            Log::info('PM: hash equal');

            $splitPaymentid = explode(':', request()->get('PAYMENT_ID'));

            $user = User::where('email', $splitPaymentid[0])->first();

            $fiatCurrency = Currency::with('networks')->whereHas('networks', function($q){
                $q->where('network_id', NETWORK_PERFECT_MONEY);
            })->first();

            if(!$user || !$fiatCurrency) {

                Log::info('PM: user or fiat doesnt exists');

                return response()->json(['status' => false])->setStatusCode(500);
            }

            if(FiatDeposit::where('type', NETWORK_PERFECT_MONEY_SLUG)->where('note', $splitPaymentid[1])->first()) {

                Log::info('PM ERROR: trying to duplicate payment');

                return response()->json(['status' => false])->setStatusCode(500);
            }

            $amount = request()->get('PAYMENT_AMOUNT');
            $fee = math_percentage($amount, $fiatCurrency->deposit_fee);

            $amountWithFee = math_sub($amount, $fee);

            $depositId = generate_uuid();

            $data = [
                'currency_id' => $fiatCurrency->id,
                'note' => $splitPaymentid[1],
                'amount' => $amountWithFee,
                'user_id' => $user->id,
                'status' => FIAT_DEPOSIT_CONFIRMED,
                'type' => 'perfectmoney',
                'deposit_id' => $depositId,
                'fee' => $fee
            ];

            (new FiatDepositRepository())->store($data);

            // Increase user wallet
            $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $fiatCurrency->id);
            $creditedAmount = (new DepositCreditService())->credit($wallet, $amountWithFee);

            Mail::to($user)->queue(new DepositReceived($user, math_formatter($creditedAmount, 2), $fiatCurrency->symbol));

            // Admin Email Notification
            $adminEmail = Setting::get('notification.admin_email', false);
            $notificationAllowed = Setting::get('notification.fiat_deposits', false);

            if($adminEmail && $notificationAllowed) {
                $route = route('admin.reports.deposits.fiat') . "?search=" . $depositId;
                Mail::to($adminEmail)->queue(new AdminDepositReceived(math_formatter($amountWithFee, 2), $fiatCurrency->symbol, $route));
            }

            Log::info('PM: true');

            return Redirect::route('wallets.deposit.fiat.success');

        } else {
            Log::info('PM: dont equal');
        }

        return response()->json(['status' => false])->setStatusCode(500);
    }
}
