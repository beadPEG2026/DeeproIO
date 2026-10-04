<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Models\User\User;
use App\Modules\P2P\Mail\Orders\BuyOrderCancelled;
use App\Modules\P2P\Mail\Orders\BuyOrderCompleted;
use App\Modules\P2P\Mail\Orders\SellOrderCompleted;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerFeedback;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderMessage;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PeerOrderRepository
{
    /**
     * @var PeerOrder
     */
    protected $peerOrder;

    /**
     * PeerPaymentMethodRepository constructor.
     *
     */
    public function __construct()
    {
        $this->peerOrder = new PeerOrder();
    }

    public function getPeerOrderById($id, $dashboard = false, $relations = null, $user = false) {

        $peerOrder = PeerOrder::whereId($id);

        if($relations !== null) {
            $peerOrder->with($relations);
        }

        $peerOrder->has('baseCurrency')->has('quoteCurrency')->has('user')->has('seller');

        if($user) {
            $peerOrder->where(function($query) use ($user){
                $query->where('user_id', $user);
                $query->orWhere('ad_user_id', $user);
            });
        }

        if(!$dashboard) {
            //$peerAd->active();
        }

        return $peerOrder->first();
    }

    public function all($paginate, $dashboard = false, $relations = [], $type = false) {

        $orders = PeerOrder::filter(request()->only(['search']))->orderByLatest();

        if(!$dashboard) {
            $orders->active();
        }

        if($type) {
            $orders->type($type);
        }

        $orders->with($relations);

        if($paginate) {
            return $orders->paginate(24)->withQueryString();
        } else {
            return $orders->get();
        }
    }

    public function count() {
        $orders = PeerOrder::query();
        return $orders->count();
    }

    public function store($data) {

        $order = $this->peerOrder->create($data);

        return $order->fresh();
    }

    public function update($id, $data) {

        $ad = PeerOrder::find($id);
        $ad->update($data);
        $ad->paymentMethods()->sync($data['paymentMethods']);

        return $ad->fresh();
    }

    public function updateStatus($id, $status) {
        $ad = PeerOrder::find($id);
        $ad->status = $status;
        $ad->update();
    }

    public function delete($id) {

        $ad = PeerOrder::find($id);
        $ad->delete();

        return true;
    }

    public function getReport($filters = [], $pagination = true) {

        $ads = PeerOrder::query();

        $ads->filter($filters)->orderBy('title', 'asc');

        if(!$pagination) {
            return $ads->get();
        }

        return $ads->paginate(150)->withQueryString();
    }

    public function getReportUser($user) {

        $ads = PeerOrder::query();

        $ads->where('user_id', $user->id);

        $ads->orderByLatest();

        return $ads->paginate(25)->withQueryString();
    }

    public function getOrders($user, $filters = []) {

        $ads = PeerOrder::query();

        $ads->userFilter($filters);

        $ads->with('baseCurrency');
        $ads->with('quoteCurrency');
        $ads->has('baseCurrency')->has('quoteCurrency');

        $ads->where(function($query) use ($user){
            $query->where('user_id', $user->id);
            $query->orWhere('ad_user_id', $user->id);
        });

        $ads->orderByLatest();

        return $ads->paginate(25)->withQueryString();
    }

    public function getOrdersReport($filters = []) {

        $ads = PeerOrder::query();

        $ads->userFilter($filters);

        $ads->with('baseCurrency');
        $ads->with('quoteCurrency');
        $ads->with('user');

        $ads->has('baseCurrency')->has('quoteCurrency')->has('user');

        $ads->orderByLatest();

        return $ads->paginate(25)->withQueryString();
    }


    public function getAds() {

        $ads = PeerOrder::filter(request()->only(['basePair', 'type']));

        $ads->orderByLatest();

        return $ads->paginate(25)->withQueryString();
    }

    public function storeMessage($data) {

        $model = new PeerOrderMessage();
        return $model->create($data);
    }

    public function getMessages($order, $user, $isReviewer = false) {

        $messages = PeerOrderMessage::query();

        $messages->where('order_id', $order->id);

        if($order->ad_user_id == $user) {
            $messages->where('is_visible_owner', true);
        } elseif($order->user_id == $user) {
            $messages->where('is_visible_counterparty', true);
        }

        if(!$isReviewer) {
            $messages->where(function ($query) use ($user) {
                $query->where('order_owner_id', $user);
                $query->orWhere('order_counterparty_id', $user);
            });
        }

        return $messages->get();
    }

    public function getFeedback($id) {
        return PeerFeedback::where('order_id', $id)->first();
    }

    public function storeFeedback($data) {

        $model = new PeerFeedback();
        $model->id = generate_uuid();
        $model->method_id = $data['method_id'];
        $model->content = $data['content'];
        $model->author_id = $data['author_id'];
        $model->post_user_id = $data['post_user_id'];
        $model->is_negative = $data['is_negative'];
        $model->is_anonymous = $data['is_anonymous'];
        $model->order_id = $data['order_id'];
        $model->save();

        return $model;
    }

    public function calculateFeedbackStats($userId) {

        $user = User::find($userId);

        $negativeFeedbacks = PeerFeedback::where('post_user_id', $user->id)->where('is_negative', true)->count();
        $positiveFeedbacks = PeerFeedback::where('post_user_id', $user->id)->where('is_negative', false)->count();
        $total = $negativeFeedbacks + $positiveFeedbacks;

        $user->feedback_positive = $positiveFeedbacks;
        $user->feedback_negative = $negativeFeedbacks;
        $user->feedback_percentage = math_percentage_of($positiveFeedbacks, $total);
        $user->update();
    }

    public function cancel($order, $isSystem = false, $user = null, $reason = null, $message = null, $isGuilty = false) {

        // Note: This method should be called within a transaction from the caller
        // The caller (PeerOrderWatcherCommand, setOrderStatus) should wrap this in DB::transaction

        $chatRepository = new PeerOrderChatRepository();

        $chatRepository->storeSystemMessage($order,'The order has been cancelled. Please contact customer support if you have any questions.', 'both');

        if($isSystem) {
            $status = "cancelled_by_system";
        } else if($user->id == $order->user_id) {
            $status = "cancelled_by_counterparty";
        } else {
            $status = "cancelled_by_owner";
        }

        $order->cancelled_by = $isSystem ? null : $user->id;

        if(!$isSystem) {

            $buyerReason = config('app.p2p.default_cancellation_reasons_buyer');
            $sellerReason = config('app.p2p.default_cancellation_reasons_seller');

            $totalBuyer = count($buyerReason);

            if (($reason > $totalBuyer)) {
                $reasonMsg = $sellerReason[$reason - $totalBuyer - 1];
            } else {
                $reasonMsg = $buyerReason[$reason - 1];

                if($order->type == "buy") {
                    $order->guilty_user_id = $order->user_id;
                } elseif($order->type == "sell") {
                    $order->guilty_user_id = $order->ad_user_id;
                }

            }

            $order->cancellation_reason = $reasonMsg;

            if($reason == $totalBuyer) {
                $order->cancellation_reason_message = $message;
            }

        } else {

            if($order->type == "buy" && $isGuilty) {
                $order->guilty_user_id = $order->user_id;
            } elseif($order->type == "sell" && $isGuilty) {
                $order->guilty_user_id = $order->ad_user_id;
            }

        }

        $walletRepository = new WalletRepository();
        $peerAdRepository = new PeerAdRepository();

        // Lock the ad for update
        $ad = PeerAd::where('id', $order->ad_id)->lockForUpdate()->first();

        if (!$ad) {
            Log::error("P2P Cancel: Ad not found for order {$order->id}");
            throw new \Exception("Ad not found");
        }

        if($order->type == "sell") {

            if(!$order->isQuoteAmount) {
                $walletAmount = $order->amount;
                $ad->remaining_amount = math_sum($ad->remaining_amount, math_sub(math_sub($order->amount, $order->fee_taker), $order->fee_maker));
            } else {
                $walletAmount = math_sum($order->amount, $order->fee_taker);
                $ad->remaining_amount = math_sum($ad->remaining_amount, math_sub($order->amount, $order->fee_maker));
            }

            $ad->save();

            $wallet = $walletRepository->getWalletByCurrency($order->user_id, $order->baseCurrency->id, false);

            if ($wallet) {
                (new WalletService())->increase($wallet, $walletAmount, 'wallet');
            } else {
                Log::warning("P2P Cancel: Wallet not found for user {$order->user_id}, currency {$order->baseCurrency->id}");
            }

            // Buyer (Order Created User) - Email notifications are queued, safe outside transaction
            Mail::to($order->seller->email)->queue(new BuyOrderCancelled($order->seller, ['order_id' => $order->id]));


        } else {

            if(!$order->isQuoteAmount) {
                $sumAmount = math_sum($order->fee_taker, $order->amount);
                $sumFee = $order->fee_maker;
            } else {
                $sumAmount = $order->amount;
                $sumFee = $order->fee_maker;
            }

            $ad->remaining_amount = math_sum($ad->remaining_amount, $sumAmount);
            $ad->fee_remaining = math_sum($ad->fee_remaining, $sumFee);
            $ad->save();

            // Buyer (Order Created User)
            Mail::to($order->user->email)->queue(new BuyOrderCancelled($order->user, ['order_id' => $order->id]));
        }

        $order->status = $status;
        $order->save();

    }

    public function release($order, $isSystem = false) {

        // Note: This method should be called within a transaction from the caller
        // The caller (setOrderStatus) should wrap this in DB::transaction

        $chatRepository = new PeerOrderChatRepository();

        $message1 = __('Your payment has been received, the funds have been sent to your account.');
        $message2 = __('You have released the funds and the buyer will receive them soon.');

        if($order->type == "buy") {
            $chatRepository->storeSystemMessage($order, $message1, 'counterparty');
            $chatRepository->storeSystemMessage($order, $message2, 'owner');

            if($order->isQuoteAmount) {
                $displayAmount = math_formatter(math_sub($order->amount, $order->fee_taker), 8);
            } else {
                $displayAmount = math_formatter($order->amount, 8);
            }

            $data = [
                'order_id' => $order->id,
                'created_at' => $order->created_at,
                'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
                'crypto_amount' => $displayAmount . ' ' . $order->baseCurrency->symbol,
                'timeframe' => $order->timeframe,
            ];

            // Buyer (Order Created User)
            Mail::to($order->user->email)->queue(new BuyOrderCompleted($order->user, $data));

            if($order->isQuoteAmount) {
                $data['crypto_amount'] = math_formatter($order->amount, 8) . ' ' . $order->baseCurrency->symbol;
            } else {
                $data['crypto_amount'] = math_formatter(math_sum($order->amount, $order->fee_taker), 8) . ' ' . $order->baseCurrency->symbol;
            }

            // Seller (Ad Owner)
            Mail::to($order->seller->email)->queue(new SellOrderCompleted($order->seller, $data));

        } else {
            $chatRepository->storeSystemMessage($order, $message1, 'owner');
            $chatRepository->storeSystemMessage($order, $message2, 'counterparty');


            $data = [
                'order_id' => $order->id,
                'created_at' => $order->created_at,
                'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
                'crypto_amount' => math_formatter(math_sum($order->amount, $order->fee_taker), 8) . ' ' . $order->baseCurrency->symbol,
                'timeframe' => $order->timeframe,
            ];

            // Seller (Order Created User)
            Mail::to($order->user->email)->queue(new SellOrderCompleted($order->user, $data));

            if($order->isQuoteAmount) {
                $data['crypto_amount'] = math_formatter(math_sub($order->amount, $order->fee_maker), 8) . ' ' . $order->baseCurrency->symbol;
            } else {
                $data['crypto_amount'] = math_formatter(math_sub(math_sub($order->amount, $order->fee_taker), $order->fee_maker), 8) . ' ' . $order->baseCurrency->symbol;
            }

            // Buy (Ad Owner)
            Mail::to($order->seller->email)->queue(new BuyOrderCompleted($order->seller, $data));
        }

        $walletRepository = new WalletRepository();

        if($order->type == "buy") {

            if($order->isQuoteAmount) {
                $creditAmount = math_sub($order->amount, $order->fee_taker);
            } else {
                $creditAmount = $order->amount;
            }

            $wallet = $walletRepository->getWalletByCurrency($order->user_id, $order->baseCurrency->id, false);

            if (!$wallet) {
                Log::error("P2P Release: Wallet not found for buyer user {$order->user_id}");
                throw new \Exception("Buyer wallet not found");
            }

            (new WalletService())->increase($wallet, $creditAmount, 'wallet');
        } else {

            $wallet = $walletRepository->getWalletByCurrency($order->ad_user_id, $order->baseCurrency->id, false);

            if (!$wallet) {
                Log::error("P2P Release: Wallet not found for seller user {$order->ad_user_id}");
                throw new \Exception("Seller wallet not found");
            }

            if($order->isQuoteAmount) {
                $creditAmount = math_sub($order->amount, $order->fee_maker);
            } else {
                $creditAmount = math_sub(math_sub($order->amount, $order->fee_taker), $order->fee_maker);
            }

            (new WalletService())->increase($wallet, $creditAmount, 'wallet');
        }

        if(!$isSystem) {
            $order->release_duration = now()->diffInSeconds($order->paid_at);
        }

        $order->status = 'completed';
        $order->save();

    }

    public function getStatReport($period) {
        return DB::table('peer_orders')
            ->selectRaw('peer_orders.pair as name, currencies.symbol, SUM(peer_orders.fee_maker) as maker_income, SUM(peer_orders.fee_taker) as taker_income, COUNT(*) as total')
            ->join('currencies', 'currencies.id', 'peer_orders.base_currency_id')
            ->where('peer_orders.status', 'completed')
            ->whereBetween('peer_orders.created_at', $period)
            ->groupByRaw('peer_orders.pair, currencies.symbol')->get();
    }

    public function getActiveOrdersQuantity($user) {

        $ads = PeerOrder::query();

        $ads->where(function($query) use ($user){
            $query->where('user_id', $user->id);
            $query->orWhere('ad_user_id', $user->id);
        });

        $ads->where(function($query) use ($user){
            $query->where('status', "pending_release");
            $query->orWhere('status', "confirm_transfer");
            $query->orWhere('status', "payment_pending");
            $query->orWhere('status', "appealed_by_counterparty");
        });

        return $ads->count();

    }
}
