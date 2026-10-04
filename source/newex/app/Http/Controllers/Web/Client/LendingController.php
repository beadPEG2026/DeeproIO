<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Lending\LendingBorrowRequest;
use App\Http\Requests\Web\Lending\LendingCollateralRequest;
use App\Http\Requests\Web\Lending\LendingPurchaseRequest;
use App\Http\Requests\Web\Lending\LendingRedeemRequest;
use App\Http\Requests\Web\Lending\LendingRepayRequest;
use App\Http\Resources\Lending\Lending as LendingResource;
use App\Http\Resources\Lending\LendingCollection;
use App\Http\Resources\Lending\LendingRepaymentCollection;
use App\Http\Resources\Lending\LendingUserCollection;
use App\Models\Lending\Lending;
use App\Models\Lending\LendingCurrencies;
use App\Models\Lending\LendingRepays;
use App\Models\Lending\LendingUser;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Lending\LendingRepository;
use App\Repositories\Lending\LendingUserRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LendingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($sort = "all")
    {
        $lendings = [];
        $repaymentId = request()->get('repayment_history', false);
        $isRepayment = false;

        if(auth()->user() && $sort == "my") {

            if($repaymentId) {
                $isRepayment = true;
                $lendings = new LendingRepaymentCollection((new LendingUserRepository())->repayments($repaymentId, auth()->user()));
            } else {
                $lendings = new LendingUserCollection((new LendingUserRepository())->get(false, auth()->user()));
            }

        } elseif($sort == "all") {
            $lendings = new LendingCollection((new LendingRepository())->get(false));
        }

        return Inertia::render('Lending/Lendings', [
            'lendings' => $lendings,
            'sort' => $sort,
            'isRepayment' => $isRepayment
        ]);
    }

    public function collaterrals(Request $request) {

        $currencyRepository = new LendingRepository();
        $currencyCollection = $currencyRepository->collaterals($request->get('id'));

        $currencies = [];

        foreach ($currencyCollection as $key=>$collateral) {
            $currencies[$collateral->currency->id] = [
                'name' => $collateral->currency->symbol,
                'id' => (string)$collateral->currency->id,
                'logo' => $collateral->currency->logo_path,

                'flex_initial_ltv' => $collateral->flex_initial_ltv,
                'flex_liquidation_ltv' => $collateral->flex_liquidation_ltv,

                'weekly_initial_ltv' => $collateral->weekly_initial_ltv,
                'weekly_liquidation_ltv' => $collateral->weekly_liquidation_ltv,

                'monthly_initial_ltv' => $collateral->monthly_initial_ltv,
                'monthly_liquidation_ltv' => $collateral->monthly_liquidation_ltv,
            ];
        }

        return response()->json($currencies);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Lending $lending)
    {
        $lending = (new LendingRepository())->getLendingById($lending->id);
        $walletRepository = new WalletRepository();

        $wallet = null;
        $balance = 0;

        if(auth()->user()) {
            $wallet = $walletRepository->getWalletByCurrency(auth()->user()->getAuthIdentifier(), $lending->currency_id, false);
            $balance = $wallet->balance_in_wallet;
        }

        if(!$wallet) {
            throw new ModelNotFoundException();
        }

        if(!$lending) {
            throw new ModelNotFoundException();
        }

        return Inertia::render('Lending/Lending', [
            'lending' => new LendingResource($lending),
            'balance' => $balance
        ]);
    }

    public function borrow(LendingBorrowRequest $request) {

        if(!config('app.lending')) {
            return response()->redirectTo('/');
        }

        $lending = Lending::where('id', $request->get('id'))->lockForUpdate()->first();
        $amount = $request->get('amount');

        $user = auth()->user();

        $type = $request->get('type', 'flexible');

        $collateralCurrency = (new LendingRepository())->getCollateralByCurrency($lending->id, $request->get('collateral_id'));

        $collateralRequired = (new LendingRepository())->getCollateralRequiredAmount(
            $type,
            $amount,
            $lending,
            $collateralCurrency,
        );



        $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $collateralCurrency->currency->id, false);
        $walletBorrow = (new WalletRepository())->getWalletByCurrency($user->id, $lending->currency_id, false);

        (new WalletService())->increase($walletBorrow, $amount, 'wallet');

        (new WalletService())->decrease($wallet, $collateralRequired['amount'], 'wallet');

        $lendingUser = new LendingUser();
        $lendingUser->user_id = $user->id;
        $lendingUser->lending_id = $lending->id;
        $lendingUser->currency_id = $lending->currency_id;
        $lendingUser->collateral_id = $collateralCurrency->currency_id;
        $lendingUser->collateral_amount = $collateralRequired['amount'];
        $lendingUser->amount = $amount;
        $lendingUser->remaining_amount = $amount;
        $lendingUser->type = $type;
        $lendingUser->save();

        return response()->json(['success' => true]);
    }

    public function repay(LendingRepayRequest $request) {

        if(!config('app.lending')) {
            return response()->redirectTo('/');
        }

        $lending = LendingUser::where('id', $request->get('id'))->with('currency')->lockForUpdate()->first();

        $user = auth()->user();
        $amount = $request->get('amount');

        $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $lending->currency_id, false);
        (new WalletService())->decrease($wallet, $request->get('amount'), 'wallet');

        $walletCollateral = (new WalletRepository())->getWalletByCurrency($user->id, $lending->collateral_id, false);

        if(math_compare($amount, $lending->remaining_amount) > -1) {
            $lending->remaining_amount = 0;
            $lending->status = "closed";

            (new WalletService())->increase($walletCollateral, $lending->collateral_amount, 'wallet');

        } else {
            $lending->remaining_amount = math_sub($lending->remaining_amount, $amount);
        }

        $lending->save();

        $repayModel = new LendingRepays();
        $repayModel->lending_id = $lending->lending->id;
        $repayModel->lending_user_id = $lending->id;
        $repayModel->user_id = $lending->user_id;
        $repayModel->currency_id = $lending->currency_id;
        $repayModel->amount = $amount;
        $repayModel->collateral_id = $lending->collateral_id;
        $repayModel->save();

        return response()->json(['success' => true]);
    }

    public function calcCollaterral(Request $request) {

        if(!config('app.lending')) {
            return response()->redirectTo('/');
        }

        $lending = (new LendingRepository())->getLendingByCurrency($request->get('currency_id'));

        $type = $request->get('type', 'flexible');

        $amount = math_formatter($request->get('amount'), 8);

        $collateralCurrency = (new LendingRepository())->getCollateralByCurrency($lending->id, $request->get('collateral_id'));

        if(!$lending || !$collateralCurrency) {
            return response()->json(['message' => 'Loanable asset not found']);
        }

        $collateralRequired = (new LendingRepository())->getCollateralRequiredAmount(
            $type,
            $amount,
            $lending,
            $collateralCurrency,
        );

        return response()->json([
            'initial_ltv' => $collateralRequired['initial_ltv'],
            'liquidation_ltv' => $collateralRequired['liquidation_ltv'],
            'amount' => $collateralRequired['amount']
        ]);
    }

    public function addCollateral(LendingCollateralRequest $request) {

        if(!config('app.lending')) {
            return response()->redirectTo('/');
        }

        $amount = math_formatter($request->get('amount'), 8);

        $lendingUser = (new LendingUserRepository())->getLendingById($request->get('id'));

        $lendingUser->collateral_amount = math_sum($amount, $lendingUser->collateral_amount);

        $lendingUser->is_notified = false;

        $lendingUser->update();

        $wallet = (new WalletRepository())->getWalletByCurrency($lendingUser->user_id, $lendingUser->collateral_id, false);

        (new WalletService())->decrease($wallet, $amount, 'wallet');

        return response()->json(['success' => true]);
    }
    public function redemptionCalculate(Request $request)
    {
        $data = $request->validate(['days' => ['required','integer','min:0','max:36500']]);
        $date = Carbon::now()->addDay()->startOfDay();
        return response()->json(['value_date'=>$date->format('Y-m-d H:i:s'),
            'redemption_date'=>$date->copy()->addDays($data['days'])->format('Y-m-d H:i:s')]);
    }
}
