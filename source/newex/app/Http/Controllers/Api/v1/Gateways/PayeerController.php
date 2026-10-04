<?php

namespace App\Http\Controllers\Api\v1\Gateways;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Wallet\FiatPayeerDepositFormRequest;
use App\Mail\Deposits\AdminDepositReceived;
use App\Mail\Deposits\DepositReceived;
use App\Models\Currency\Currency;
use App\Models\Deposit\FiatDeposit;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Deposit\DepositCreditService;
use App\Services\PaymentGateways\Fiat\Payeer\Services\Payeer;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;
use App\Models\User\User;
use DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Setting;

class PayeerController extends Controller {

    #[ExcludeRouteFromDocs]
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|void
     */
    public function ipn(Request $request)
    {

        Log::info('Initiate Payeer Payment');

        $type = $request->get('type');

        if($type == "fail") {
            Log::info('Initiate Payeer Payment Failed');
            die('Payment has been failed.');
            return Redirect::route('wallets.deposit.fiat.failed');

        } elseif($type == "success") {
            Log::info('Initiate Payeer Payment Success');
            die('Payment has been made successfully.');
            return Redirect::route('wallets.deposit.fiat.success');

        }

        if (!in_array($_SERVER['REMOTE_ADDR'], array('185.71.65.92', '185.71.65.189', '149.202.17.210'))) {
            Log::info('Wrong IP address for Payeer Payment');
            die('Wrong IP address for Payeer Payment');
        }

        // Note: Sensitive payment data not logged for security
        Log::info('Payeer IPN received for order: ' . $request->get('m_orderid'));

        $m_key = config('payeer.merchant_key');
        $m_shop = $request->get('m_shop');
        $m_orderid = $request->get('m_orderid');
        $m_operation_id = $request->get('m_operation_id');
        $m_operation_ps = $request->get('m_operation_ps');
        $m_operation_date = $request->get('m_operation_date');
        $m_operation_ps = $request->get('m_operation_ps');
        $m_operation_pay_date = $request->get('m_operation_pay_date');
        $amount = $request->get('m_amount');
        $m_curr = $request->get('m_curr');
        $m_desc = $request->get('m_desc');
        $checksum = $request->get('m_sign');
        $m_status = $request->get('m_status');
        $user = User::where('email', base64_decode($m_desc))->first();

        if (isset($m_operation_id) && isset($checksum)) {

            $arHash = array($m_operation_id, $m_operation_ps, $m_operation_date, $m_operation_pay_date, $m_shop, $m_orderid, $amount, $m_curr, $m_desc, $m_status, $m_key);
            $sign_hash = strtoupper(hash('sha256', implode(':', $arHash)));

                if ($checksum == $sign_hash && $m_status == 'success' && !FiatDeposit::where('type', NETWORK_PAYEER_SLUG)->where('note', $m_orderid)->first()) {

                    $fiatCurrency = Currency::with('networks')->whereHas('networks', function($q){
                        $q->where('network_id', NETWORK_PAYEER);
                    })->first();

                    if(!$user || !$fiatCurrency) {

                        Log::info('PM: user or fiat doesnt exists');

                        die($m_orderid . '|error');
                        //return response()->json(['status' => false])->setStatusCode(500);
                    }

                    $fee = math_percentage($amount, $fiatCurrency->deposit_fee);

                    $amountWithFee = math_sub($amount, $fee);

                    $depositId = generate_uuid();

                    $data = [
                        'currency_id' => $fiatCurrency->id,
                        'note' => $m_orderid,
                        'amount' => $amountWithFee,
                        'user_id' => $user->id,
                        'status' => FIAT_DEPOSIT_CONFIRMED,
                        'type' => NETWORK_PAYEER_SLUG,
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
                    return Redirect::route('wallets.deposit.fiat.success');
                } else {
                    die($m_orderid . '|error');
                }
        } else {
            die($m_orderid . '|error');
        }
        return Redirect::route('wallets.deposit.fiat.failed');
    }

    #[ExcludeRouteFromDocs]
    public function checkout(FiatPayeerDepositFormRequest $request) {

        $user = auth()->user();

        if(!$user) {
            return response()->json(['success' => false])->setStatusCode(500);
        }

        $service = new Payeer();

        $url = $service->payeerCheckout($request->get('amount'), time(), $user->email);

        Log::info($url);

        return $url;
    }
}
