<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Voucher\VoucherCollection;
use App\Models\Voucher\Voucher;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Setting;

class VoucherController extends Controller
{
    /**
     * Redeem voucher code.
     *
     * @return \Illuminate\Http\Response
     */
    public function redeem(Request $request)
    {
        $user = auth()->user();
        $code = $request->get('code');

        $voucher = Voucher::with('currency')->has('currency')->whereCode($code)->active()->whereNull('user_id')->first();

        if(!$voucher) return response()->json(['message' => __('Voucher code is invalid or already used.')]);

        return DB::transaction(function() use ($user, $voucher) {

            $walletRepository = new WalletRepository();
            $walletService = new WalletService();

            // Increase user wallet
            $wallet = $walletRepository->getWalletByCurrency($user->id, $voucher->currency->id);
            $walletService->increase($wallet, $voucher->amount);

            $voucher->is_redeemed = true;
            $voucher->user_id = $user->id;
            $voucher->redeemed_at = Carbon::now();
            $voucher->update();

            return response()->json(['message' => __("Voucher code for {$voucher->amount} {$voucher->currency->symbol} was successfully redeemed.")]);

        });

    }

    /**
     * Create new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function transactions()
    {
        $user = auth()->user();

        $vouchers = Voucher::where('user_id', $user->id)->with('currency')->has('currency')->redeemed()->limit(100)->orderBy('id', 'desc')->get();

        return response()->json(new VoucherCollection($vouchers));
    }

}
