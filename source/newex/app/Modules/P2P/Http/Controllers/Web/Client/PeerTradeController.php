<?php

namespace App\Modules\P2P\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Currency\CurrencyLiteCollection;
use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Http\Resources\PaymentMethodCollection;
use App\Modules\P2P\Http\Resources\PeerAd;
use App\Modules\P2P\Http\Resources\PeerAdCustom;
use App\Modules\P2P\Http\Resources\PeerOrder;
use App\Modules\P2P\Http\Resources\PeerOrderAppealCollection;
use App\Modules\P2P\Http\Resources\UserPaymentMethod;
use App\Modules\P2P\Http\Resources\UserPaymentMethodCollection;
use App\Modules\P2P\Models\PeerTrade\PeerAdPayment;
use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderAppealRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerPaymentMethodRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Http\Resources\User\User as UserResource;
use Illuminate\Support\Facades\Request as RequestFacade;

class PeerTradeController extends Controller
{

    public function create(Request $request)
    {
        $peerConfigs = config('app.p2p');
        $peerAdRepository = new PeerAdRepository();

        $user = $request->user()->id;

        $defaultBasePair = $peerConfigs['default_base_pair'];
        $defaultQuotePair = $peerConfigs['default_quote_pair'];

        $peerPaymentMethods = $peerAdRepository->getPeerUserPaymentMethods($user);

        $peerPaymentMethods = (new UserPaymentMethodCollection($peerPaymentMethods))->response()->getData(true)['data'];

        return Inertia::render('PeerTrade/CreatePeerTrade', [
            'isEdit' => false,
            'basePair' => $defaultBasePair,
            'quotePair' => $defaultQuotePair,
            'paymentMethods' => $peerPaymentMethods,
            'userPaymentMethodIds' => null
        ]);
    }


    public function edit($id = false)
    {
        $repository = new PeerAdRepository();
        $user = auth()->user();

        if(!is_uuid_valid($id)) {
            return redirect(route('p2p.my-ads'));
        }

        $ad = $repository->getPeerAdById($id, false, ['paymentMethods', 'baseCurrency', 'quoteCurrency', 'regions'], $user->id);

        if(!$ad) {
            return redirect(route('p2p.my-ads'));
        }

        $record = new PeerAd($ad);

        $ad = $record->response()->getData();

        $peerPaymentMethods = $repository->getPeerUserPaymentMethods($user->id);

        $peerPaymentMethods = (new UserPaymentMethodCollection($peerPaymentMethods))->response()->getData(true)['data'];

        $userPaymentMethodIds = PeerAdPayment::where('ad_id', $id)->pluck('user_payment_method_id');

        return Inertia::render('PeerTrade/CreatePeerTrade', [
            'isEdit' => true,
            'ad' => $ad->data,
            'paymentMethods' => $peerPaymentMethods,
            'userPaymentMethodIds' => $userPaymentMethodIds,
        ]);
    }

    public function postSuccess() {
        return Inertia::render('PeerTrade/PeerAdSuccess');
    }

    public function myAds(Request $request) {

        $peerAdRepository = new PeerAdRepository();
        $currencyRepository = new CurrencyRepository();

        $filters = [
            'type' => request()->get('type'),
            'status' => request()->get('status', 'active'),
            'ad_id' => request()->get('ad_id'),
            'coin' => request()->get('coin'),
            'fiat' => request()->get('fiat'),
            'date' => request()->get('date'),
        ];

        $ads = PeerAdCustom::collection($peerAdRepository->getReportUser(auth()->user(), true, $filters))->response()->getData(true);
        $currencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'coin'));
        $fiatCurrencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'fiat'));

        return Inertia::render('PeerTrade/MyAds', [
            'ads' => $ads,
            'currencies' => $currencies,
            'fiatCurrencies' => $fiatCurrencies,
        ]);
    }

    public function myOrders(Request $request) {

        $peerOrderRepository = new PeerOrderRepository();
        $currencyRepository = new CurrencyRepository();

        $filters = [
            'type' => request()->get('type'),
            'status' => request()->get('status'),
            'order_id' => request()->get('order_id'),
            'coin' => request()->get('coin'),
            'date' => request()->get('date'),
        ];

        $orders = PeerOrder::collection($peerOrderRepository->getOrders(auth()->user(), $filters))->response()->getData(true);
        $currencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'coin'));
        $fiatCurrencies = new CurrencyLiteCollection($currencyRepository->all(false, false, [], 'fiat'));

        return Inertia::render('PeerTrade/MyOrders', [
            'orders' => $orders,
            'currencies' => $currencies,
            'fiatCurrencies' => $fiatCurrencies,
        ]);
    }

    public function myOrder($id = false)
    {
        $repository = new PeerOrderRepository();
        $peerAdRepository = new PeerAdRepository();
        $user = auth()->user();

        if(!is_uuid_valid($id)) {
            return redirect(route('p2p.my-orders'));
        }

        $order = $repository->getPeerOrderById($id, false, ['ad', 'seller'], $user->id);

        if(!$order) {
            return redirect(route('p2p.my-orders'));
        }

        $record = new PeerOrder($order);

        $orderObject = $record->response()->getData();

        if($user->getAuthIdentifier() == $order->ad->user_id) {

            if($order->type == "sell") {
                $isBuy = false;
                $userID = $order->user_id;

                $peerPaymentMethods = $peerAdRepository->getPeerUserPaymentMethods($userID, [$order->user_payment_method_id]);
                $userPaymentMethod = $peerAdRepository->getPeerUserPaymentMethod($order->user_payment_method_id, $userID);

            } else {
                $isBuy = true;
                $userID = $order->user_id;

                $peerPaymentMethods = $peerAdRepository->getPeerAdPaymentMethods($order->ad->id, $userID);
                $userPaymentMethod = $peerAdRepository->getPeerUserPaymentMethod($order->user_payment_method_id, $order->ad->user_id);
            }

        } else {

            if($order->type == "sell") {
                $isBuy = true;
                $userID = $order->ad->user_id;
                $userPaymentMethod = $peerAdRepository->getPeerUserPaymentMethod($order->user_payment_method_id, $order->user_id);
            } else {
                $isBuy = false;
                $userID = $order->ad->user_id;
                $userPaymentMethod = $peerAdRepository->getPeerUserPaymentMethod($order->user_payment_method_id, $userID);
            }

            $peerPaymentMethods = $peerAdRepository->getPeerAdPaymentMethods($order->ad->id, $userID);
        }

        $peerPaymentMethods = (new UserPaymentMethodCollection($peerPaymentMethods))->response()->getData(true)['data'];

        if($userPaymentMethod) {
            $userPaymentMethod = (new UserPaymentMethod($userPaymentMethod))->response()->getData(true)['data'];
        } else {
            $userPaymentMethod = [];
        }

        return Inertia::render('PeerTrade/MyOrder', [
            'model' => $orderObject->data,
            'paymentMethods' => $peerPaymentMethods,
            'userPaymentMethod' => $userPaymentMethod,
            'isBuy' => $isBuy,
            'buyerCancellationReasons' => config('app.p2p.default_cancellation_reasons_buyer'),
            'sellerCancellationReasons' => config('app.p2p.default_cancellation_reasons_seller')
        ]);
    }

    public function ads(Request $request) {

        $peerAdRepository = new PeerAdRepository();
        $peerConfigs = config('app.p2p');
        $user = auth()->user();
        $peerPaymentMethods = [];

        $defaultBasePair = $peerConfigs['default_base_pair'];
        $defaultQuotePair = $peerConfigs['default_quote_pair'];

        $quoteParam = request()->get('quotePair', false);

        if($user && $user->default_fiat_currency && !$quoteParam) {
            $defaultQuotePair = $user->default_fiat_currency;
        }

        if($quoteParam && $user && $user->default_fiat_currency !== $quoteParam) {
            $user->default_fiat_currency = mb_substr($quoteParam, 0, 3);
            $user->save();
        }

        $ads = PeerAd::collection($peerAdRepository->getAds($defaultBasePair, $defaultQuotePair))->response()->getData(true);

        $baseCurrencies = Currency::type('coin')->active()->where('is_p2p', true)->orderBy('is_stable', 'desc')->pluck('symbol');
        $quoteCurrencies = Currency::type('fiat')->active()->where('is_p2p', true)->get('symbol');

        if($user) {
            $peerPaymentMethods = $peerAdRepository->getPeerUserPaymentMethods($user->id);

            if($peerPaymentMethods) {
                $peerPaymentMethods = (new UserPaymentMethodCollection($peerPaymentMethods))->response()->getData(true)['data'];
            }
        }

        $walletRepository = new WalletRepository();


        $baseCurrency = (new CurrencyRepository())->getCurrencyBySymbol($defaultBasePair);

        $takerSellFee = 0;
        $takerBuyFee = 0;

        if($baseCurrency) {
            $takerSellFee = $baseCurrency->p2p_taker_sell_fee;
            $takerBuyFee = $baseCurrency->p2p_taker_buy_fee;
        }

        return Inertia::render('PeerTrade/Ads', [
            'filters' => RequestFacade::all(['type','basePair','quotePair', 'payment_method','amount','region', 'sort', 'merchants_only', 'timeframe']),
            'ads' => $ads,
            'basePair' => $defaultBasePair,
            'quotePair' => $defaultQuotePair,
            'baseCurrencies' => $baseCurrencies,
            'quoteCurrencies' => $quoteCurrencies,
            'userPaymentMethods' => $peerPaymentMethods,
            'takerSellFee' => $takerSellFee,
            'takerBuyFee' => $takerBuyFee
        ]);
    }

    public function profile($id = false) {

        $user = User::where('referral_code', $id)->first();

        $loggedUser = auth()->user();

        if(!$user) {
           return redirect('/');
        }

        $owner = false;

        if($loggedUser && $loggedUser->id == $user->id) {
           $owner = true;
        }

        $userResource = new UserResource($user);

        $peerAdRepository = new PeerAdRepository();

        $isBlocked = false;

        if($loggedUser) {
            $isBlocked = $peerAdRepository->isUserInBlacklist($loggedUser->id, $user->id);
        }

        return Inertia::render('PeerTrade/Profile', [
            'seller' => $userResource->response()->getData()->data,
            'owner' => $owner,
            'blockReasons' => config('app.p2p.block_reasons'),
            'userBlocked' => $isBlocked
        ]);
    }

    public function paymentMethods($id = false) {

        $peerPaymentMethods = new PaymentMethodCollection((new PeerPaymentMethodRepository())->all(false, false));
        $paymentMethod = null;

        if($id) {
            $paymentMethod = PeerUserPaymentMethod::where('id', $id)->where('user_id', request()->user()->id)->first();

            if (!$paymentMethod) {
                $id = false;
            }

            $paymentMethod = (new UserPaymentMethod($paymentMethod))->response()->getData()->data;
        }

        return Inertia::render('PeerTrade/PaymentMethods', [
            'id' => $id,
            'paymentMethod' => $paymentMethod,
            'methods' => $peerPaymentMethods->response()->getData()->data,
        ]);

    }

    public function myAppeal($id = false) {

        $repository = new PeerOrderAppealRepository();
        $user = auth()->user();

        if(!is_uuid_valid($id)) {
            return redirect(route('p2p.my-orders'));
        }

        $appeals = $repository->getAppealsById($id, $user);

        $repository = new PeerOrderRepository();

        $order = $repository->getPeerOrderById($id, false, ['ad', 'seller'], $user->id);

        if(!$order) {
            return redirect(route('p2p.my-orders'));
        }

        $stage = 1;

        if($order->appeal_stage == "responded") {
            $stage = 3;
        } elseif($order->appeal_stage == "moderated") {
            $stage = 4;
        }


        $record = new PeerOrder($order);

        $recordAppeals = new PeerOrderAppealCollection($appeals);

        $orderObject = $record->response()->getData();

        $isInitiator = $order->appealed_by == $user->id;
        $isReviewer = $user->hasRole('p2p_appeal_judge');

        return Inertia::render('PeerTrade/MyAppeal', [
            'stage' => $stage,
            'isReviewer' => $isReviewer,
            'isInitiator' => $isInitiator,
            'appeals' => $recordAppeals->response()->getData()->data,
            'order' => $orderObject->data,
        ]);
    }
}
