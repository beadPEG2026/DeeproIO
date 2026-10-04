<?php

namespace App\Modules\P2P\Http\Controllers\Api\v1;

use App\Events\ChatMessageTyped;
use App\Http\Controllers\Controller;
use App\Http\Resources\Currency\CurrencyLogoCollection;
use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\User\User;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeChangePaymentMethodRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeFeedbackDeleteFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeFeedbackEditFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeOrderAppealCancelRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeOrderAppealRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeOrderAppealRespondRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeOrderMessageStoreRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostAdFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostDeleteFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostEditFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostFeedbackRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostOrderRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostReplyFeedbackRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostStatusFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradePostUserPaymentMethodFormRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeSetOrderStatusRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerTradeSetUsernameRequest;
use App\Modules\P2P\Http\Requests\Api\PeerTrade\PeerUserBlacklistFormRequest;
use App\Modules\P2P\Http\Resources\PaymentFieldCollection;
use App\Modules\P2P\Http\Resources\PaymentMethodCollection;
use App\Modules\P2P\Http\Resources\PeerAdCustom;
use App\Modules\P2P\Http\Resources\PeerFeedback;
use App\Modules\P2P\Http\Resources\PeerOrderMessageCollection;
use App\Modules\P2P\Http\Resources\UserPaymentMethodCollection;
use App\Modules\P2P\Mail\Orders\BuyOrderCreated;
use App\Modules\P2P\Mail\Orders\SellOrderCreated;
use App\Modules\P2P\Mail\Orders\SellOrderRelease;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerPaymentField;
use App\Modules\P2P\Models\PeerTrade\PeerUserBlacklist;
use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use App\Modules\P2P\Repositories\PeerTrade\PeerAdRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderAppealRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderChatRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerPaymentFieldRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerPaymentMethodRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Modules\P2P\Http\Resources\PeerOrder as PeerOrderResource;
use App\Modules\P2P\Models\PeerTrade\PeerFeedback as PeerFeedbackModel;
use Illuminate\Support\Facades\Mail;

#[ExcludeAllRoutesFromDocs]
class PeerPaymentApiController extends Controller
{

    #[ExcludeRouteFromDocs]
    public function assets()
    {
        $currencyRepository = new CurrencyRepository();

        $coins = new CurrencyLogoCollection($currencyRepository->all(false, false, ['file'], 'coin', true));
        $fiats = new CurrencyLogoCollection($currencyRepository->all(false, false, ['file'], 'fiat', true));

        return response()->json([
            'coins' => $coins,
            'fiats' => $fiats
        ]);
    }

    public function paymentMethods(Request $request)
    {
        $currency = $request->get('currency');

        $paymentMethods = new PeerPaymentMethodRepository();

        $methods = new PaymentMethodCollection($paymentMethods->all(false, false, ['currencies'], false, $currency));

        return response()->json($methods);
    }

    public function paymentMethodFields(Request $request) {

        $paymentFields = new PeerPaymentFieldRepository();

        $fields = new PaymentFieldCollection($paymentFields->all(false, false, $request->get('id'), true));

        return response()->json($fields);

    }

    public function getPairBestRate(Request $request) {

        $base = $request->get('base');
        $quote = $request->get('quote');

        $baseCurrency = Currency::where('symbol', $base)->first();
        $usdtCurrency = Currency::where('symbol', 'USDT')->first();
        $quoteCurrency = Currency::where('symbol', $quote)->first();

        if(!$usdtCurrency || !$baseCurrency || !$quoteCurrency) {
            return [
                'highestOrderPrice' => 0.00,
                'lowestOrderPrice' => 0.00,
                'rate' => 0
            ];
        }

        $adRepository = new PeerAdRepository();
        $usdRate = $adRepository->getUsdRate($baseCurrency->id, $usdtCurrency->id);
        $rate = $adRepository->getFiatRate($quoteCurrency->id, $usdRate);

        $lowest = $adRepository->getLowestOrderPrice($baseCurrency->id, $quoteCurrency->id, $rate);
        $highest = $adRepository->getHighestOrderPrice($baseCurrency->id, $quoteCurrency->id, $rate);

        return [
            'highestOrderPrice' => math_formatter($highest, get_p2p_decimals($baseCurrency->symbol)),
            'lowestOrderPrice' => math_formatter($lowest, get_p2p_decimals($baseCurrency->symbol)),
            'rate' => $rate,
            'buy_fee' => $baseCurrency->p2p_maker_buy_fee,
            'sell_fee' => $baseCurrency->p2p_maker_sell_fee,
        ];
    }

    public function getFeedbacks(Request $request) {

        $peerAdRepository = new PeerAdRepository();

        $type = $request->get('type');

        $user = $request->get('user');

        if(!$user) {
            return response()->json(false);
        }

        $feedbacks = $peerAdRepository->getFeedbacks($user, $type);

        $data = PeerFeedback::collection($feedbacks)->response()->getData(true);

        return response()->json($data);
    }

    public function getFeedbackStats(Request $request) {

        $user = User::where('referral_code', $request->get('user'))->first();

        if(!$user) {
            return response()->json([
                'status' => false
            ]);
        }

        return response()->json([
            'positive' => $user->feedback_positive,
            'negative' => $user->feedback_negative,
            'percentage' => math_formatter($user->feedback_percentage, 2),
            'total' => $user->feedback_positive + $user->feedback_negative
        ]);
    }

    public function postAd(PeerTradePostAdFormRequest $request) {

        try {
            return DB::transaction(function() use ($request) {
                $post = $request->all();
                $adType = $post['side'];
                $priceType = $post['type'];
                $user = auth()->user();

                $baseCurrency = Currency::type('coin')->where('symbol', $request->get('coin'))->active()->where('is_p2p', true)->first();
                $quoteCurrency = Currency::type('fiat')->where('symbol', $request->get('fiat'))->active()->where('is_p2p', true)->first();

                if (!$baseCurrency || !$quoteCurrency) {
                    return response()->json(['status' => false, 'message' => 'Invalid currency'], 422);
                }

                $usdt = DB::table('currencies')->where('symbol', 'USDT')->value('id');

                // Get and lock wallet for sell ads
                $walletRepository = new WalletRepository();
                $wallet = $walletRepository->getWalletByCurrency($user->id, $baseCurrency->id, false);

                $fee = '0';
                $feeRate = $walletRepository->getPeerFeeRate($baseCurrency, $adType);

                if($adType == "sell") {
                    if (!$wallet) {
                        return response()->json(['status' => false, 'message' => 'Wallet not found'], 422);
                    }

                    $fee = $walletRepository->calculatePeerFee($post['amount'], $baseCurrency, $adType);
                    $totalRequired = math_sum($post['amount'], $fee);

                    // Check balance before proceeding
                    if (math_compare($wallet->balance_in_wallet, $totalRequired) < 0) {
                        return response()->json(['status' => false, 'message' => 'Insufficient balance'], 422);
                    }

                    // Decrease wallet first (atomically with ad creation)
                    (new WalletService())->decrease($wallet, $totalRequired, 'wallet');
                }

                $usdtMarket = Market::where('base_currency_id', $baseCurrency->id)->where('quote_currency_id', $usdt)->first();

                $data = [
                    'id' => generate_uuid(),
                    'user_id' => $user->id,
                    'base_currency_id' => $baseCurrency->id,
                    'quote_currency_id' => $quoteCurrency->id,
                    'amount' => $post['amount'],
                    'remaining_amount' => $post['amount'],
                    'fee_rate' => $feeRate,
                    'fee_reserved' => $fee,
                    'fee_remaining' => $fee,
                    'min_amount' => math_formatter($post['min_amount'], get_p2p_decimals($baseCurrency->symbol)),
                    'max_amount' => math_formatter($post['max_amount'], get_p2p_decimals($baseCurrency->symbol)),
                    'type' => $adType,
                    'price_type' => $priceType,
                    'price' => $priceType == "fixed" ? $post['fixed_price'] : 0,
                    'price_percentage' => $priceType == "float" ? $post['floated_price'] : 0,
                    'timeframe' => $post['timeframe'],
                    'remarks' => $post['remarks'] ? strip_tags($post['remarks']) : null,
                    'auto_reply' => $post['auto_reply'] ? strip_tags($post['auto_reply']) : null,
                    'status' => 'active',
                    'paymentMethods' => $post['paymentMethods'],
                    'regions' => $post['regions'] ?? null,
                    'market_id' => $usdtMarket ? $usdtMarket->id : null,
                ];

                $peerAdRepository = new PeerAdRepository();
                $peerAdRepository->store($data);

                return response()->json([
                    'status' => true
                ]);
            }, 5);

        } catch (\Throwable $e) {
            Log::error("P2P Ad creation failed: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Ad creation failed. Please try again.'], 500);
        }
    }

    public function editAd(PeerTradePostEditFormRequest $request) {

        try {
            return DB::transaction(function() use ($request) {
                $post = $request->all();
                $priceType = $post['type'];
                $user = auth()->user();
                $id = $request->get('id');

                // Lock the ad for update
                $ad = PeerAd::where('id', $id)->where('user_id', $user->id)->lockForUpdate()->first();

                if (!$ad) {
                    return response()->json(['status' => false, 'message' => 'Ad not found'], 404);
                }

                $baseCurrency = Currency::type('coin')->where('id', $ad->base_currency_id)->active()->where('is_p2p', true)->first();

                if (!$baseCurrency) {
                    return response()->json(['status' => false, 'message' => 'Currency not available'], 422);
                }

                $usdt = DB::table('currencies')->where('symbol', 'USDT')->value('id');
                $usdtMarket = Market::where('base_currency_id', $baseCurrency->id)->where('quote_currency_id', $usdt)->first();

                $data = [
                    'user_id' => $user->id,
                    'min_amount' => math_formatter($post['min_amount'], get_p2p_decimals($baseCurrency->symbol)),
                    'max_amount' => math_formatter($post['max_amount'], get_p2p_decimals($baseCurrency->symbol)),
                    'price_type' => $priceType,
                    'price' => $priceType == "fixed" ? $post['fixed_price'] : 0,
                    'price_percentage' => $priceType == "float" ? $post['floated_price'] : 0,
                    'timeframe' => $post['timeframe'],
                    'remarks' => $post['remarks'] ? strip_tags($post['remarks']) : null,
                    'auto_reply' => $post['auto_reply'] ? strip_tags($post['auto_reply']) : null,
                    'paymentMethods' => $post['paymentMethods'],
                    'regions' => $post['regions'] ?? null,
                    'market_id' => $usdtMarket ? $usdtMarket->id : null,
                ];

                // Check if amount is being changed using math_compare for precision
                if(math_compare($post['amount'], $ad->remaining_amount) !== 0) {

                    $walletRepository = new WalletRepository();
                    $wallet = $walletRepository->getWalletByCurrency($ad->user_id, $ad->base_currency_id, false);

                    if (!$wallet) {
                        return response()->json(['status' => false, 'message' => 'Wallet not found'], 422);
                    }

                    // Amount is increasing
                    if(math_compare($post['amount'], $ad->remaining_amount) > 0) {

                        $newAmount = math_sub($post['amount'], $ad->remaining_amount);

                        $data['amount'] = $post['amount'];
                        $data['remaining_amount'] = $post['amount'];

                        if($ad->type == "sell") {
                            $newFee = $walletRepository->calculatePeerFee($newAmount, $baseCurrency, 'sell', true, $ad->fee_rate);
                            $totalRequired = math_sum($newAmount, $newFee);

                            // Check balance
                            if (math_compare($wallet->balance_in_wallet, $totalRequired) < 0) {
                                return response()->json(['status' => false, 'message' => 'Insufficient balance'], 422);
                            }

                            (new WalletService())->decrease($wallet, $totalRequired, 'wallet');
                            $data['fee_remaining'] = math_sum($ad->fee_remaining, $newFee);
                        }

                    } else {
                        // Amount is decreasing

                        $newAmount = math_sub($ad->remaining_amount, $post['amount']);

                        $data['amount'] = $post['amount'];
                        $data['remaining_amount'] = $post['amount'];

                        if($ad->type == "sell") {
                            $newFee = $walletRepository->calculatePeerFee($newAmount, $baseCurrency, 'sell', true, $ad->fee_rate);
                            (new WalletService())->increase($wallet, math_sum($newAmount, $newFee), 'wallet');
                            $data['fee_remaining'] = math_sub($ad->fee_remaining, $newFee);
                        }
                    }
                }

                $peerAdRepository = new PeerAdRepository();
                $peerAdRepository->update($id, $data);

                return response()->json([
                    'status' => true
                ]);
            }, 5);

        } catch (\Throwable $e) {
            Log::error("P2P Ad edit failed: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Ad update failed. Please try again.'], 500);
        }
    }

    public function setStatus(PeerTradePostStatusFormRequest $request) {

        $status = $request->get('status') == 'active' ? 'active' : 'draft';
        $id = $request->get('id');

        $peerAdRepository = new PeerAdRepository();

        $peerAdRepository->updateStatus($id, $status);

        return response()->json([
            'status' => $status
        ]);
    }

    public function getOrderStatus(Request $request) {

        $id = $request->get('id');

        if(!$id || !is_uuid_valid($id)) {
            return response()->json([
                'success' => false
            ]);
        }

        $peerOrderRepository = new PeerOrderRepository();

        $order = $peerOrderRepository->getPeerOrderById($id);

        if(!$order) {
            return response()->json([
                'success' => false
            ]);
        }

        $record = new PeerOrderResource($order);

        return response()->json([
            'order' => $record->response()->getData()->data
        ]);
    }

    public function deleteAd(PeerTradePostDeleteFormRequest $request) {

        $id = $request->get('id');

        $peerAdRepository = new PeerAdRepository();

        $peerAdRepository->updateStatus($id, 'closed');

        $ad = $peerAdRepository->getPeerAdById($id);

        if($ad->type == "sell") {
            $walletRepository = new WalletRepository();
            $wallet = $walletRepository->getWalletByCurrency($ad->user_id, $ad->baseCurrency->id, false);
            (new WalletService())->increase($wallet, math_sum($ad->remaining_amount, $ad->fee_remaining), 'wallet');
        }

        return response()->json([
            'status' => true
        ]);
    }

    public function setOrder(PeerTradePostOrderRequest $request) {

        try {
            return DB::transaction(function() use ($request) {
                $post = $request->all();
                $user = auth()->user();
                $isQuoteAmount = $request->get('isQuoteAmount', false);

                $peerOrderRepository = new PeerOrderRepository();
                $peerAdRepository = new PeerAdRepository();

                // Lock the ad to prevent race conditions
                $ad = PeerAd::where('id', $post['ad_id'])
                    ->with(['baseCurrency', 'quoteCurrency'])
                    ->lockForUpdate()
                    ->first();

                if (!$ad || $ad->status !== 'active') {
                    return response()->json(['status' => false, 'message' => 'Ad is not available'], 422);
                }

                $usdt = DB::table('currencies')->where('symbol', 'USDT')->value('id');

                $usdRate = $peerAdRepository->getUsdRate($ad->baseCurrency->id, $usdt);
                $rate = $peerAdRepository->getFiatRate($ad->quoteCurrency->id, $usdRate);

                if($isQuoteAmount) {
                    $amount = math_divide($post['amount'], $rate);
                } else {
                    $amount = $post['amount'];
                }

                $walletRepository = new WalletRepository();
                $takerFee = $walletRepository->calculatePeerFee($amount, $ad->baseCurrency, $ad->type == "sell" ? 'buy' : 'sell', false);
                $makerFee = $walletRepository->calculatePeerFee($amount, $ad->baseCurrency, $ad->type, true, $ad->fee_rate);

                $price = $ad->price;

                if($ad->price_type == "float") {
                    $floatRate = $ad->market_id ? market_get_stats($ad->market_id, 'last') : 1;
                    $price = math_divide(math_multiply(math_multiply($ad->baseCurrency->rate, $floatRate), $ad->price_percentage), '100');
                }

                if($isQuoteAmount) {
                    $quoteAmount = $post['amount'];
                } else {
                    if($ad->type == "sell") {
                        $quoteAmount = math_multiply(math_sum($amount, $takerFee), $price);
                    } else {
                        $quoteAmount = math_multiply(math_sub($amount, $takerFee), $price);
                    }
                }

                $quoteAmount = math_formatter($quoteAmount, 2, true);

                if($ad->type == "sell") {
                    $userPaymentMethod = PeerUserPaymentMethod::where('user_id', $ad->user_id)->where('payment_method', $post['payment_method'])->first();
                } else {
                    $userPM = PeerUserPaymentMethod::where('id', $post['payment_method'])->first();
                    $userPaymentMethod = PeerUserPaymentMethod::where('user_id', $user->id)->where('payment_method', $userPM->payment_method)->first();
                }

                if (!$userPaymentMethod) {
                    return response()->json(['status' => false, 'message' => 'Payment method not found'], 422);
                }

                if($ad->type == "sell") {

                    if(!$isQuoteAmount) {

                        $totalAmount = math_sum($amount, $takerFee);

                        $makerFee = $walletRepository->calculatePeerFee($totalAmount, $ad->baseCurrency, $ad->type, true, $ad->fee_rate);

                        // Check if ad has enough remaining amount
                        if (math_compare($ad->remaining_amount, $totalAmount) < 0) {
                            return response()->json(['status' => false, 'message' => 'Insufficient ad amount available'], 422);
                        }

                        $ad->remaining_amount = math_sub($ad->remaining_amount, $totalAmount);
                        $ad->fee_remaining = math_sub($ad->fee_remaining, $makerFee);

                    } else {

                        if (math_compare($ad->remaining_amount, $amount) < 0) {
                            return response()->json(['status' => false, 'message' => 'Insufficient ad amount available'], 422);
                        }

                        $ad->remaining_amount = math_sub($ad->remaining_amount, $amount);
                        $ad->fee_remaining = math_sub($ad->fee_remaining, $makerFee);

                    }

                } else {

                    if($isQuoteAmount) {
                        $sellAmount = math_sub($amount, $makerFee);
                    } else {

                        $sellAmount = math_sub($amount, $takerFee);

                        $makerFee = $walletRepository->calculatePeerFee($sellAmount, $ad->baseCurrency, $ad->type, true, $ad->fee_rate);

                        $sellAmount = math_sub($sellAmount, $makerFee);

                    }

                    if (math_compare($ad->remaining_amount, $sellAmount) < 0) {
                        return response()->json(['status' => false, 'message' => 'Insufficient ad amount available'], 422);
                    }

                    $ad->remaining_amount = math_sub($ad->remaining_amount, $sellAmount);
                }

                $ad->save();

                $data = [
                    'id' => generate_uuid(),
                    'user_id' => $user->id,
                    'ad_user_id' => $ad->user_id,
                    'amount' => $amount,
                    'payment_method_id' => $userPaymentMethod->payment_method,
                    'user_payment_method_id' => $userPaymentMethod->id,
                    'quote_amount' => $quoteAmount,
                    'base_currency_id' => $ad->base_currency_id,
                    'quote_currency_id' => $ad->quote_currency_id,
                    'pair' => $ad->baseCurrency->symbol . '-' . $ad->quoteCurrency->symbol,
                    'ad_id' => $ad->id,
                    'price' => $price,
                    'status' => 'payment_pending',
                    'type' => $ad->type == "buy" ? 'sell' : 'buy',
                    'timeframe' => $ad->timeframe,
                    'fee_maker' => $makerFee,
                    'fee_taker' => $takerFee,
                    'isQuoteAmount' => $isQuoteAmount,
                ];

                $order = $peerOrderRepository->store($data);

                if($order->type == "sell") {
                    $wallet = $walletRepository->getWalletByCurrency($order->user_id, $ad->baseCurrency->id, false);

                    if (!$wallet) {
                        throw new \Exception('Wallet not found');
                    }

                    if($isQuoteAmount) {
                        $orderAmount = math_sum($order->amount, $takerFee);
                    } else {
                        $orderAmount = $order->amount;
                    }

                    if (math_compare($wallet->balance_in_wallet, $orderAmount) < 0) {
                        throw new \Exception('Insufficient wallet balance');
                    }

                    (new WalletService())->decrease($wallet, $orderAmount, 'wallet');
                }

                if($rate > 0) {
                    $fiatRemainingAmount = math_multiply($ad->remaining_amount, $rate);

                    if(math_compare($fiatRemainingAmount, $ad->min_amount) < 0) {
                        $ad->status = "hidden";
                        $ad->hidden_reason = 'less_min_amount';
                        $ad->save();
                    }
                }

                // Set Chat Messages (outside transaction is fine - non-critical)
                $chatRepository = new PeerOrderChatRepository();

                $message1 = __('Successfully placed an order, please pay within the time limit.');
                $message2 = __("Successfully placed an order, please wait for buyer to pay.");

                if($order->type == "buy") {
                    $chatRepository->storeSystemMessage($order,$message1, 'counterparty');
                    $chatRepository->storeSystemMessage($order,$message2, 'owner');

                    if($order->isQuoteAmount) {
                        $displayAmount = math_formatter(math_sub($order->amount, $order->fee_taker), 8);
                    } else {
                        $displayAmount = math_formatter($order->amount, 8);
                    }

                    $emailData = [
                        'order_id' => $order->id,
                        'created_at' => $order->created_at,
                        'fiat_amount' => $order->quote_amount . ' ' . $ad->quoteCurrency->symbol,
                        'crypto_amount' =>  $displayAmount. ' ' . $ad->baseCurrency->symbol,
                        'timeframe' => $order->timeframe,
                    ];

                    // Buyer (Order Created User)
                    Mail::to($order->user->email)->queue(new BuyOrderCreated($order->user, $emailData));

                    if($order->isQuoteAmount) {
                        $emailData['crypto_amount'] = math_formatter($order->amount, 8) . ' ' . $ad->baseCurrency->symbol;
                    } else {
                        $emailData['crypto_amount'] = math_formatter(math_sum($order->amount, $order->fee_taker), 8) . ' ' . $ad->baseCurrency->symbol;
                    }

                    // Seller (Ad Owner)
                    Mail::to($order->seller->email)->queue(new SellOrderCreated($order->seller, $emailData));

                } elseif($order->type == "sell") {
                    $chatRepository->storeSystemMessage($order,$message1, 'owner');
                    $chatRepository->storeSystemMessage($order,$message2, 'counterparty');

                    $emailData = [
                        'order_id' => $order->id,
                        'created_at' => $order->created_at,
                        'fiat_amount' => $order->quote_amount . ' ' . $ad->quoteCurrency->symbol,
                        'crypto_amount' => math_formatter(math_sum($order->amount, $order->fee_taker), 8) . ' ' . $ad->baseCurrency->symbol,
                        'timeframe' => $order->timeframe,
                    ];

                    // Seller (Order Created User)
                    Mail::to($order->user->email)->queue(new SellOrderCreated($order->user, $emailData));

                    if($order->isQuoteAmount) {
                        $emailData['crypto_amount'] = math_formatter(math_sub($order->amount, $order->fee_maker), 8) . ' ' . $ad->baseCurrency->symbol;
                    } else {
                        $emailData['crypto_amount'] = math_formatter(math_sub(math_sub($order->amount, $order->fee_taker), $order->fee_maker), 8) . ' ' . $ad->baseCurrency->symbol;
                    }

                    // Buyer (Ad Owner)
                    Mail::to($order->seller->email)->queue(new BuyOrderCreated($order->seller, $emailData));
                }

                if($ad->auto_reply) {

                    $post['message'] = $ad->auto_reply;
                    $post['order_id'] = $order->id;
                    $post['is_author'] = false;
                    $post['type'] = 'message';
                    $post['user_id'] = $ad->user_id;
                    $post['is_visible_owner'] = true;
                    $post['is_visible_counterparty'] = true;
                    $post['order_owner_id'] = $order->user_id;
                    $post['order_counterparty_id'] = $order->ad_user_id;
                    $post['id'] = generate_uuid();

                    $peerOrderRepository->storeMessage($post);
                }

                return response()->json([
                    'id' => $order->id,
                    'status' => true
                ]);
            }, 5); // 5 retries on deadlock

        } catch (\Throwable $e) {
            Log::error("P2P Order creation failed: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Order creation failed. Please try again.'], 500);
        }
    }

    public function setOrderStatus(PeerTradeSetOrderStatusRequest $request) {

        try {
            return DB::transaction(function() use ($request) {
                $id = $request->get('id');
                $status = $request->get('status');
                $reason = intval($request->get('reason'));
                $message = $request->get('message');
                $user = auth()->user();

                $peerOrderRepository = new PeerOrderRepository();

                // Lock the order for update
                $order = PeerOrder::where('id', $id)
                    ->with(['baseCurrency', 'quoteCurrency', 'user', 'seller'])
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    return response()->json(['status' => false, 'message' => 'Order not found'], 404);
                }

                if($status == "confirm_transfer") {
                    // Validate order can be confirmed
                    if ($order->status !== 'payment_pending') {
                        return response()->json(['status' => false, 'message' => 'Order cannot be confirmed in current state'], 422);
                    }

                    $chatRepository = new PeerOrderChatRepository();

                    $message1 = __("You have marked the order as paid, please wait for seller to confirm and release the asset.");
                    $message2 = __("Counterparty has marked the order as paid. Please confirm that you have received the payment and release the asset. Please note: Make sure to log into your account and confirm that you have received the payment before releasing the asset to avoid loss.");

                    if($order->type == "buy") {
                        $chatRepository->storeSystemMessage($order,$message1, 'counterparty');
                        $chatRepository->storeSystemMessage($order,$message2, 'owner');


                        $data = [
                            'order_id' => $order->id,
                            'created_at' => $order->created_at,
                            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
                            'crypto_amount' => math_formatter($order->amount, 8) . ' ' . $order->baseCurrency->symbol,
                            'timeframe' => $order->timeframe,
                        ];

                        if(!$order->isQuoteAmount) {
                            $data['crypto_amount'] = math_formatter(math_sum($order->amount, $order->fee_taker), 8) . ' ' . $order->baseCurrency->symbol;
                        }

                        Mail::to($order->seller->email)->queue(new SellOrderRelease($order->seller, $data));

                    } else {
                        $chatRepository->storeSystemMessage($order,$message1, 'owner');
                        $chatRepository->storeSystemMessage($order,$message2, 'counterparty');

                        $data = [
                            'order_id' => $order->id,
                            'created_at' => $order->created_at,
                            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
                            'crypto_amount' => math_formatter(math_sum($order->amount, $order->fee_taker), 8) . ' ' . $order->baseCurrency->symbol,
                            'timeframe' => $order->timeframe,
                        ];

                        Mail::to($order->user->email)->queue(new SellOrderRelease($order->user, $data));
                    }

                    $order->paid_duration = abs((int)$order->created_at->diffInSeconds(now()));
                    $order->paid_at = now();
                    $order->status = $status;
                    $order->save();


                } elseif($status == "cancel") {

                    // Validate order can be cancelled
                    if (!in_array($order->status, ['payment_pending', 'confirm_transfer'])) {
                        return response()->json(['status' => false, 'message' => 'Order cannot be cancelled in current state'], 422);
                    }

                    $peerOrderRepository->cancel($order, false, $user, $reason, $message);

                } elseif($status == "completed") {

                    // Validate order can be completed
                    if ($order->status !== 'confirm_transfer') {
                        return response()->json(['status' => false, 'message' => 'Order cannot be completed in current state'], 422);
                    }

                    $peerOrderRepository->release($order);

                }

                return response()->json([
                    'id' => $order->id,
                    'status' => $status
                ]);
            }, 5);

        } catch (\Throwable $e) {
            Log::error("P2P Order status update failed: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Order status update failed. Please try again.'], 500);
        }
    }

    public function getOrderMessage(Request $request) {

        $user = auth()->user();

        $id = $request->get('order_id');

        if(!is_uuid_valid($id)) return false;

        // Find order and verify user is either the order owner, counterparty, or a judge
        $order = PeerOrder::where('id', $id)
            ->where(function($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhere('ad_user_id', $user->id);
            })
            ->first();

        // Allow judges to access any order
        if(!$order && $user->hasRole('p2p_appeal_judge')) {
            $order = PeerOrder::find($id);
        }

        if(!$order) return false;

        $peerOrderRepository = new PeerOrderRepository();
        $messages = new PeerOrderMessageCollection($peerOrderRepository->getMessages($order, $user->id, $user->hasRole('p2p_appeal_judge')));

        return response()->json([
            'messages' => $messages
        ]);
    }

    public function postOrderMessage(PeerTradeOrderMessageStoreRequest $request) {

        $post = $request->only([
            'message',
            'order_id',
        ]);

        $user = $request->user();

        $order = PeerOrder::where('id', $post['order_id'])->first();

        // Security: Verify user is a participant in this order
        if (!$order || ($order->user_id !== $user->id && $order->ad_user_id !== $user->id)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $post['is_author'] = $order->user_id == $user->id;
        $post['type'] = 'message';
        $post['user_id'] = $user->id;
        $post['is_visible_owner'] = true;
        $post['is_visible_counterparty'] = true;
        $post['order_owner_id'] = $order->user_id;
        $post['order_counterparty_id'] = $order->ad_user_id;
        $post['id'] = generate_uuid();

        $peerOrderRepository = new PeerOrderRepository();
        $peerOrderRepository->storeMessage($post);

        return response()->json([
            'status' => true
        ]);
    }

    public function chatMessage(Request $request) {

        $post = $request->only([
            'type',
            'order_id',
        ]);

        if(!isset($post['order_id']) || !is_uuid_valid($post['order_id'])) {
            return response()->json([
                'status' => false
            ]);
        }

        $user = $request->user();
        $order = PeerOrder::where('id', $post['order_id'])->first();

        if($order->user_id !== $user->id && $order->ad_user_id !== $user->id) {
            return response()->json([
                'status' => false
            ]);
        }

        if(isset($post['type']) && $post['type'] == "typing") {

            $userId = $order->ad_user_id;

            if($user->id == $order->ad_user_id) {
                $userId = $order->user_id;
            }

            event(new ChatMessageTyped($userId, $order->id));
        }

        return response()->json([
            'status' => true
        ]);

    }

    public function getOrderFeedback(Request $request) {

        $order_id = $request->get('order_id');

        $peerOrderRepository = new PeerOrderRepository();
        $feedback = $peerOrderRepository->getFeedback($order_id);

        if(!$feedback) {
            return response()->json([
                'feedback' => null
            ]);
        }

        $paymentMethod = (new PeerFeedback($feedback))->response()->getData()->data;

        return response()->json([
            'feedback' => $paymentMethod
        ]);
    }

    public function postOrderFeedback(PeerTradePostFeedbackRequest $request) {

        $post = $request->only([
            'content',
            'order_id',
            'is_anonymous'
        ]);

        $order = PeerOrder::where('id', $request->get('order_id'))->with('seller')->first();

        if(!$order || $order->status !== "completed") return false;

        $isNegative = (bool)$request->get('is_negative');

        $user = $request->user();
        $post['author_id'] = $user->id;
        $post['post_user_id'] = $order->ad_user_id;
        $post['is_negative'] = $isNegative;
        $post['is_anonymous'] = (bool)$request->get('is_anonymous');
        $post['method_id'] = $order->payment_method_id;

        $peerOrderRepository = new PeerOrderRepository();
        $peerOrderRepository->storeFeedback($post);
        $peerOrderRepository->calculateFeedbackStats($order->ad_user_id);

        $order->seller->update();

        return response()->json([
            'status' => true
        ]);
    }

    public function deleteFeedback(PeerTradeFeedbackDeleteFormRequest $request) {

        $id = $request->get('id');

        $peerAdRepository = new PeerAdRepository();

        $peerAdRepository->deleteFeedback($id);

        return response()->json([
            'status' => true
        ]);
    }

    public function deleteFeedbackReply(PeerTradeFeedbackDeleteFormRequest $request) {

        $id = $request->get('id');

        $peerAdRepository = new PeerAdRepository();

        $peerAdRepository->deleteFeedbackReply($id);

        return response()->json([
            'status' => true
        ]);
    }

    public function editFeedback(PeerTradeFeedbackEditFormRequest $request) {

        $post = $request->all();
        $id = $request->get('id');

        $data = [
            'is_negative' => $post['is_negative'],
            'is_anonymous' => $post['is_anonymous'],
            'content' => $post['content'],
        ];

        $peerAdRepository = new PeerAdRepository();

        $peerAdRepository->updateFeedback($id, $data);

        return response()->json([
            'status' => true
        ]);
    }

    public function postUserPaymentMethod(PeerTradePostUserPaymentMethodFormRequest $request) {

        $fields = $request->get('form');
        $method = $request->get('id');
        $post_id = $request->get('post_id');
        $user = $request->user()->id;

        if(!$fields || !$method) return;

        $group = [];

        foreach ($fields as $id=>$field) {

            $model = PeerPaymentField::where('payment_method', $method)->where('id', $id)->first();

            if(!$model) continue;

            $group[] = ['field_id' => $id, 'content' => mb_substr($field, 0, 80)];
        }

        if(!$post_id) {

            $model = new PeerUserPaymentMethod();
            $model->payment_method = $method;
            $model->content = json_encode($group);
            $model->user_id = $user;
            $model->save();

        } else {
            $model = PeerUserPaymentMethod::where('id', $post_id)->where('user_id', $user)->first();
            $model->content = json_encode($group);
            $model->update();
        }

        return response()->json([
            'status' => true
        ]);
    }

    public function getUserPaymentMethods(Request $request) {

        $user = User::where('referral_code', $request->get('user'))->first();

        $paymentMethods = new UserPaymentMethodCollection(PeerUserPaymentMethod::where('user_id', $user->id)->active()->with('paymentMethod')->orderBy('id')->get());

        return response()->json($paymentMethods);

    }

    public function deleteUserPaymentMethod(Request $request) {

        $id = $request->get('id');

        $model = PeerUserPaymentMethod::where('id', $id)->where('user_id', $request->user()->id)->first();

        if(!$model) return;

        $model->is_archived = true;
        $model->save();

        return response()->json([
            'status' => true
        ]);

    }

    public function changePaymentMethod(PeerTradeChangePaymentMethodRequest $request)
    {
        $post = $request->all();
        $user = auth()->user();

        $peerOrderRepository = new PeerOrderRepository();

        $order = $peerOrderRepository->getPeerOrderById($post['order_id']);

        $paymentMethod = PeerUserPaymentMethod::where('id', $post['id'])->first();

        $order->user_payment_method_id = $paymentMethod->id;
        $order->payment_method_id = $paymentMethod->payment_method;
        $order->update();

        return response()->json([
            'id' => $order->order_id,
            'status' => true
        ]);
    }

    public function setUsername(PeerTradeSetUsernameRequest $request) {

        $user = $request->user();

        $model = User::where('id', $user->id)->first();

        $model->peer_username = $request->get('username');
        $model->peer_username_updated_at = now();
        $model->update();

        return response()->json([
            'status' => true
        ]);
    }

    public function getAdInfo(Request $request) {

        $ad_id = $request->get('ad_id');

        if(!$ad_id || !is_uuid_valid($ad_id)) {
            return response()->json([
                'status' => false
            ]);
        }

        $peerAdRepository = new PeerAdRepository();

        $ad = new PeerAdCustom($peerAdRepository->getAd($ad_id));

        return response()->json([
            'ad' => $ad
        ]);

    }

    public function getAds(Request $request) {

        $peerAdRepository = new PeerAdRepository();

        $filters['type'] = $request->get('type', null);

        $user = User::where('referral_code', $request->get('user'))->first();

        if(!$user) return;

        $ads = PeerAdCustom::collection($peerAdRepository->getReportUser($user, false, $filters))->response()->getData(true);

        return response()->json([
            'ads' => $ads['data']
        ]);
    }

    public function submitAppeal(PeerTradeOrderAppealRequest $request) {

        $peerOrderAppeal = new PeerOrderAppealRepository();

        $data = $request->all();

        $peerOrderAppeal->storeAppeal($data);

        return response()->json([
            'success' => true
        ]);
    }

    #[ExcludeRouteFromDocs]
    public function submitAppealReview(Request $request) {

        $peerOrderAppeal = new PeerOrderAppealRepository();

        $data = $request->all();

        $peerOrderAppeal->submitAppealReview($data);

        return response()->json([
            'success' => true
        ]);

    }

    public function respondAppeal(PeerTradeOrderAppealRespondRequest $request) {

        $peerOrderAppeal = new PeerOrderAppealRepository();

        $data = $request->all();

        $peerOrderAppeal->respondAppeal($data);

        return response()->json([
            'success' => true
        ]);
    }

    public function cancelAppeal(PeerTradeOrderAppealCancelRequest $request) {

        $peerOrderAppeal = new PeerOrderAppealRepository();

        $data = $request->all();

        $peerOrderAppeal->cancelAppeal($data);

        return response()->json([
            'success' => true
        ]);
    }

    public function blockUser(PeerUserBlacklistFormRequest $request) {

        $user = $request->get('user');
        $type = $request->get('type');
        $reason = $request->get('reason');

        $reasons = config('app.p2p.block_reasons');

        if($type != "5") {
            $reason = $reasons[$type - 1];
        }

        $userModel = User::where('referral_code', $user)->first();

        $model = new PeerUserBlacklist();
        $model->user_id = $request->user()->id;
        $model->blocked_user_id = $userModel->id;
        $model->reason = $reason;
        $model->save();

        return response()->json([
            'success' => true
        ]);
    }

    public function unblockUser(Request $request) {

        $loggedUser = $request->user();
        $user = $request->get('user');

        $userModel = User::where('referral_code', $user)->first();

        if(!$userModel) {
            return response()->json([
                'success' => false
            ]);
        }

        PeerUserBlacklist::where('user_id', $loggedUser->id)->where('blocked_user_id', $userModel->id)->delete();

        return response()->json([
            'success' => true
        ]);
    }

    public function replyFeedback(PeerTradePostReplyFeedbackRequest $request) {

        $feedback = $request->get('feedback_id');
        $reply = $request->get('reply');

        if(!is_uuid_valid($feedback)) {
            return response()->json([
                'success' => false
            ]);
        }

        $model = PeerFeedbackModel::where('id', $feedback)->first();
        $model->reply_content = $reply;
        $model->update();

        return response()->json([
            'success' => true
        ]);
    }

    public function processingFee(Request $request) {

        $defaultBasePair = $request->get('symbol');

        if(!$defaultBasePair) return;

        $baseCurrency = (new CurrencyRepository())->getCurrencyBySymbol($defaultBasePair);

        $takerSellFee = 0;
        $takerBuyFee = 0;

        if($baseCurrency) {
            $takerSellFee = $baseCurrency->p2p_taker_sell_fee;
            $takerBuyFee = $baseCurrency->p2p_taker_buy_fee;
        }

        return response()->json([
            'takerSellFee' => $takerSellFee,
            'takerBuyFee' => $takerBuyFee
        ]);
    }

    public function getActiveOrdersQuantity(Request $request) {

        $quantity = (new PeerOrderRepository())->getActiveOrdersQuantity($request->user());

        return response()->json([
            'quantity' => $quantity
        ]);
    }
}
