<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Staking\StakingPurchaseRequest;
use App\Http\Requests\Web\Staking\StakingRedeemRequest;
use App\Http\Resources\Staking\Staking as StakingResource;
use App\Http\Resources\Staking\StakingCollection;
use App\Http\Resources\Staking\StakingUserCollection;
use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use App\Repositories\Staking\StakingRepository;
use App\Repositories\Staking\StakingUserRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Staking\StakingWalletService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StakingController extends Controller
{

public function index($sort = "all")
{
    $stakingType = request()->get('staking_type', 0);
    $stakings = [];

    if(auth()->user() && $sort == "my") {
        $stakings = new StakingUserCollection(
            (new StakingUserRepository())->get(false, auth()->user(), $stakingType)
        );
    } elseif($sort == "all") {
        // 关联 currency 和 currencyd
        $stakings = new StakingCollection(
            (new StakingRepository())->get(false, $stakingType)
        );
    }

    return Inertia::render('Staking/Stakings', [
        'stakings' => $stakings,
        'sort' => $sort,
        'staking_type' => $stakingType,
        'rewardEstimate' => $this->rewardEstimate($stakingType)
    ]);
}


private function rewardEstimate($type): ?string
{
    if (!auth()->check()) return null;
    $total='0';$prices=new \App\Repositories\Currency\CurrencyRepository();
    $stakes=StakingUser::where('user_id',auth()->id())->whereIn('status',['active','completed'])
        ->whereHas('staking',fn($q)=>$q->where('staking_type',$type))->with('staking.currency')->get();
    foreach($stakes as $stake) {
        if (bccomp((string)$stake->reward,'0',18)<=0) continue;
        $currency=$stake->staking?->currency;
        $rate=$currency ? $prices->currencyPriceInUsd($currency) : 0;
        if ($rate<=0) return null;
        $total=bcadd($total,bcmul((string)$stake->reward,(string)$rate,18),18);
    }
    return $total;
}

public function show(Staking $staking)
{
    $stakingType = request()->get('staking_type', 0);

    // 获取 staking，确保 eager load currency & currencyd
    $staking = (new StakingRepository())->getStakingById($staking->id);

    // 如果量化 staking_type=1，确保 currencyd 被加载
    if ($stakingType == 1 && !$staking->relationLoaded('currencyd')) {
        $staking->load('currencyd.file');
    }
    
    $walletRepository = new WalletRepository();
    $stakingWalletService = new StakingWalletService();
    $wallet = null;
    $balanceInfo = [
        'account_type' => 'real',
        'field' => 'balance_in_wallet',
        'balance' => '0',
    ];

    if(auth()->user()) {
        $currencyId = $stakingType == 1 ? $staking->currency_idd : $staking->currency_id;
        $wallet = $walletRepository->getWalletByCurrency(auth()->user()->getAuthIdentifier(), $currencyId, false);
        $balanceInfo = $stakingWalletService->getAvailableBalanceInfo(auth()->user(), $wallet);
        if(app(\App\Services\Staking\FundedTermProduct::class)->enabled($staking))$balanceInfo=['account_type'=>'spot','field'=>'balance_in_trade','balance'=>(string)($wallet?->balance_in_trade??'0')];
    }

    if(!$staking) {
        throw new ModelNotFoundException();
    }

    return Inertia::render('Staking/Staking', [
        'staking' => new StakingResource($staking),
        'balance' => number_format((float) $balanceInfo['balance'], 8, '.', ''),
        'staking_account_type' => $balanceInfo['account_type'],
        'staking_type' => $stakingType
    ]);
}

    public function submit(StakingPurchaseRequest $request) {
        return DB::transaction(function () use ($request) {
            $staking = Staking::where('id', $request->get('id'))->lockForUpdate()->first();

            if (!$staking) {
                return response()->json(['success' => false, 'message' => 'Staking not found'], 404);
            }

            $user = auth()->user();
            $days = $request->get('days');
            $amount = $request->get('amount');

            $funded=app(\App\Services\Staking\FundedTermProduct::class);
            if($funded->enabled($staking)) {
                $funded->subscribe($staking,$user,(string)$amount,(int)$days);
                return response()->json(['success'=>true]);
            }
            if($staking->status!=='active') abort(422);

            // Validate APY exists for the selected days
            $apy = get_apy_by_day($days, $staking->allowed_days, $staking->rewards_percentage);
            
            if ($apy === null) {
                return response()->json(['success' => false, 'message' => 'Invalid staking duration'], 422);
            }

            $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $staking->currency_id, true);

            if (!$wallet) {
                return response()->json(['success' => false, 'message' => 'Wallet not found'], 404);
            }

            $stakingWalletService = new StakingWalletService();
            $debitSource = $stakingWalletService->getDebitSource($user, $wallet, $amount);
            $stakingWalletService->decrease($wallet, $debitSource['field'], $amount);

            $valueDate = Carbon::now()->addDays(1);

            $stakingUser = new StakingUser();
            $stakingUser->user_id = $user->id;
            $stakingUser->staking_id = $staking->id;
            $stakingUser->currency_id = $staking->currency_id;
            $stakingUser->amount = $amount;
            $stakingUser->days = $days;
            $stakingUser->apy = $apy;
            $stakingUser->reward = '0';
            $stakingUser->value_date = $valueDate->format('Y-m-d 00:00:00');
            $stakingUser->redemption_date = $valueDate->addDays((int)$days)->format('Y-m-d 00:00:00');

            if ($stakingWalletService->canStoreStakingMeta()) {
                $stakingUser->meta = $stakingWalletService->sourceMeta($debitSource);
            }

            $stakingUser->save();

            return response()->json(['success' => true]);
        });

    }

    public function redeem(StakingRedeemRequest $request) {

        return DB::transaction(function () use ($request) {
            $staking = StakingUser::where('id', $request->get('id'))->with('currency')->lockForUpdate()->first();

            if (!$staking) {
                return response()->json(['success' => false, 'message' => 'Staking not found'], 404);
            }

            $user = auth()->user();
            abort_unless((int)$staking->user_id===(int)$user->id && $staking->status==='active',422);
            if (!empty($staking->meta['funded_term'])) {
                app(\App\Services\Staking\FundedTermProduct::class)->settle($staking->id,$user->id);
                return response()->json(['success'=>true]);
            }

            $wallet = (new WalletRepository())->getWalletByCurrency($user->id, $staking->currency->id, true);

            if (!$wallet) {
                return response()->json(['success' => false, 'message' => 'Wallet not found'], 404);
            }
            
            // Return principal amount + accumulated rewards to the original real/virtual wallet.
            $totalAmount = math_sum($staking->amount, $staking->reward ?? '0');
            $stakingWalletService = new StakingWalletService();
            $returnField = $stakingWalletService->getReturnBalanceField($staking);
            $stakingWalletService->increase($wallet, $returnField, $totalAmount);

            $staking->status = "redeemed";
            $staking->save();

            return response()->json(['success' => true]);
        });
    }

    public function redemptionCalculate(Request $request) {

        $date = Carbon::now()->addDays(1);

        return response()->json([
            'value_date' => $date->format('Y-m-d 00:00:00'),
            'redemption_date' => $date->addDays((int)$request->get('days'))->format('Y-m-d 00:00:00')
        ]);
    }
}
