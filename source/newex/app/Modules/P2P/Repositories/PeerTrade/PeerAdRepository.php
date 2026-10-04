<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\User\User;
use App\Modules\P2P\Http\Resources\UserPaymentMethod;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerFeedback;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerUserBlacklist;
use App\Modules\P2P\Models\PeerTrade\PeerUserPaymentMethod;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Auth;
use Illuminate\Support\Facades\DB;

class PeerAdRepository
{
    /**
     * @var PeerAd
     */
    protected $peerAd;

    /**
     * PeerPaymentMethodRepository constructor.
     *
     */
    public function __construct()
    {
        $this->peerAd = new PeerAd();
    }

    public function getPeerAdById($id, $dashboard = false, $relations = null, $user = false) {

        $peerAd = PeerAd::whereId($id);

        if($relations !== null) {
            $peerAd->with($relations);
        }

        $peerAd->has('baseCurrency')->has('quoteCurrency')->has('user');

        if($user) {
            $peerAd->where('user_id', $user);
        }

        if(!$dashboard) {
            //$peerAd->active();
        }

        return $peerAd->first();
    }

    public function getFeedbacks($user, $type) {

        $feedbacks = PeerFeedback::query();

        $userModel = User::where('referral_code', $user)->first();

        if($type == "positive") {
            $feedbacks->where('is_negative', false);
        } elseif($type == "negative") {
            $feedbacks->where('is_negative', true);
        }

        if(!$userModel) return;

        $feedbacks->with(['paymentMethod', 'seller', 'author']);

        $feedbacks->where('post_user_id', $userModel->id);

        return $feedbacks->paginate(2);
    }

    public function getPeerFeedbackById($id, $user = false, $reply = false) {

        $peerFeedback = PeerFeedback::whereId($id);

        if($reply) {
            $peerFeedback->where('post_user_id', $user);
        } else {
            $peerFeedback->where('author_id', $user);
        }

        return $peerFeedback->first();
    }

    public function all($paginate, $dashboard = false, $relations = [], $type = false) {

        $ads = PeerAd::filter(request()->only(['search']))->orderByLatest();

        if(!$dashboard) {
            $ads->active();
        }

        if($type) {
            $ads->type($type);
        }

        $ads->with($relations);

        if($paginate) {
            return $ads->paginate(24)->withQueryString();
        } else {
            return $ads->get();
        }
    }

    public function count() {
        $ads = PeerAd::query();
        return $ads->count();
    }

    public function store($data) {

        $ad = $this->peerAd->create($data);
        $syncIds = [];

        if($data['type'] == "buy") {

            foreach ($data['paymentMethods'] as $id) {
                $syncIds[] = ['ad_id' => $ad->id, 'payment_method_id' => $id];
            }

            $ad->paymentMethods()->sync($syncIds);

        } else {

            $syncIds = [];

            $methods = PeerUserPaymentMethod::whereIn('id', $data['paymentMethods'])->get();

            foreach ($methods as $method) {
                $syncIds[] = ['ad_id' => $ad->id, 'payment_method_id' => $method->payment_method, 'user_payment_method_id' => $method->id];
            }

            $ad->paymentMethods()->sync($syncIds);
        }

        $ad->regions()->sync($data['regions']);

        return $ad->fresh();
    }

    public function update($id, $data) {

        $ad = PeerAd::find($id);
        $ad->update($data);
        $syncIds = [];

        if($ad->type == "buy") {

            foreach ($data['paymentMethods'] as $id) {
                $syncIds[] = ['ad_id' => $ad->id, 'payment_method_id' => $id,];
            }

            $ad->paymentMethods()->sync($syncIds);


        } else {

            $methods = PeerUserPaymentMethod::whereIn('id', $data['paymentMethods'])->get();

            foreach ($methods as $method) {
                $syncIds[] = ['ad_id' => $ad->id, 'payment_method_id' => $method->payment_method, 'user_payment_method_id' => $method->id];
            }

            $ad->paymentMethods()->sync($syncIds);
        }

        $ad->regions()->sync($data['regions']);

        return $ad->fresh();
    }

    public function updateFeedback($id, $data) {

        $feedback = PeerFeedback::where('id', $id)->with('seller')->first();

        $feedback->content = $data['content'];
        $feedback->is_anonymous = $data['is_anonymous'];
        $feedback->is_negative = $data['is_negative'];
        $feedback->update();

        $peerOrderRepository = new PeerOrderRepository();

        $peerOrderRepository->calculateFeedbackStats($feedback->seller->id);

        return $feedback->fresh();
    }

    public function updateStatus($id, $status) {
        $ad = PeerAd::find($id);
        $ad->status = $status;
        $ad->update();
    }

    public function delete($id) {

        $ad = PeerAd::where('id', $id);

        $ad->delete();

        return true;
    }

    public function deleteFeedback($id) {

        $feedback = PeerFeedback::where('id', $id)->with('seller')->first();
        $feedback->delete();

        $peerOrderRepository = new PeerOrderRepository();
        $peerOrderRepository->calculateFeedbackStats($feedback->seller->id);

        return true;
    }

    public function deleteFeedbackReply($id) {
        $feedback = PeerFeedback::find($id);
        $feedback->reply_content = null;
        $feedback->update();

        return true;
    }



    public function getReport($filters = [], $pagination = true) {

        $ads = PeerAd::query();

        $ads->filter($filters)->orderBy('title', 'asc');

        if(!$pagination) {
            return $ads->get();
        }

        return $ads->paginate(150)->withQueryString();
    }

    public function getReportUser($user, $paginate = true, $filters = []) {

        $ads = PeerAd::query();

        $ads->userFilter($filters);

        $ads->where('user_id', $user->id);

        $ads->with('paymentMethods');
        $ads->with('baseCurrency.file');
        $ads->with('quoteCurrency.file');

        $ads->orderByLatest();

        if($paginate) {
            return $ads->paginate(25)->withQueryString();
        }

        return $ads->get();
    }

    public function getAdsReport() {

        $ads = PeerAd::filter(request()->only(['basePair', 'quotePair', 'type', 'payment_method', 'amount', 'region', 'sort']));

        $ads->with('paymentMethods');

        $ads->with('baseCurrency');
        $ads->with('quoteCurrency');
        $ads->with('user');

        $ads->has('baseCurrency')->has('quoteCurrency')->has('user');

        $ads->orderByLatest();

        return $ads->paginate(30)->withQueryString();
    }

    public function getAds($defaultBasePair, $defaultQuotePair) {

        $baseParam = request()->get('basePair', $defaultBasePair);
        $quoteParam = request()->get('quotePair', $defaultQuotePair);

        $usdt = DB::table('currencies')->where('symbol', 'USDT')->value('id');
        $base = DB::table('currencies')->where('symbol', $baseParam)->value('id');
        $quote = DB::table('currencies')->where('symbol', $quoteParam)->value('id');

        $usdRate = $this->getUsdRate($base, $usdt);
        $rate = $this->getFiatRate($quote, $usdRate);

        $ads = PeerAd::filter(request()->only(['basePair', 'quotePair', 'type', 'payment_method', 'amount', 'region', 'sort']));

        $ads->select(DB::raw("peer_ads.*, (CASE WHEN price_type = 'fixed' THEN price ELSE ({$rate} * price_percentage) / 100 END) as offered_price"));

        $ads->with('paymentMethods');

        $ads->with('baseCurrency.file');
        $ads->with('quoteCurrency.file');

        $ads->active();

        $user = auth()->user();

        if($user) {

            $userIds = PeerUserBlacklist::where('user_id', $user->id)->pluck('blocked_user_id')->toArray();
            $userIds[] = $user->id;

            $ads->whereNotIn('user_id', $userIds);
        }

        $sort = request()->get('sort');
        $merchantsOnly = request()->get('merchants_only');
        $timeframe = request()->get('timeframe');

        $ads->leftJoin('users', 'peer_ads.user_id', '=', 'users.id');

        if($merchantsOnly) {
            $ads->whereNotNull('users.merchant_verified_at');
        }

        if($timeframe && $timeframe !== "all") {
            $ads->whereTimeframe($timeframe);
        }

        if($sort == "completion_rate") {

            $ads->orderBy('users.orders_completion_rate', 'DESC');

        } elseif($sort == "feedback_rate") {

            $ads->orderBy('users.feedback_percentage', 'DESC');

        } elseif($sort == "orders_completed") {

            $ads->orderBy('users.orders_completed_thirty', 'DESC');

        } elseif($sort == "price") {
            $ads->with('user');
            $ads->orderBy(DB::raw('offered_price'), request()->get('type') == 'buy' ? 'ASC' : 'DESC');
        } else {
            $ads->with('user');
            $ads->orderBy(DB::raw('offered_price'), request()->get('type') == 'buy' ? 'ASC' : 'DESC');
        }

        return $ads->paginate(25)->withQueryString();
    }

    public function getAd($ad_id) {

        $ads = PeerAd::query();

        $ads->where('id', $ad_id);

        $ads->with('paymentMethods');
        $ads->with('baseCurrency.file');
        $ads->with('quoteCurrency.file');

        return $ads->first();
    }

    public function getPeerAdPaymentMethods($id, $user_id) {
        $ids = DB::table('peer_ads_payment_methods')->where('ad_id', $id)->pluck('user_payment_method_id');
        return PeerUserPaymentMethod::where('user_id', $user_id)->whereIn('id', $ids)->get();
    }

    public function getPeerUserPaymentMethods($user_id, $ids = false) {

        $query = PeerUserPaymentMethod::query();

        $query->where('user_id', $user_id);

        $query->active();

        if($ids) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    public function getPeerUserPaymentMethod($paymentMethod, $user) {
        return PeerUserPaymentMethod::where('id', $paymentMethod)->where('user_id', $user)->first();
    }

    public function getUsdRate($base, $quote) {

        if($base == $quote) return 1;

        $market = Market::where('base_currency_id', $base)->where('quote_currency_id', $quote)->first();

        if(!$market) return 0;

        return market_get_stats($market->id, 'last');
    }

    public function getFiatRate($quote, $rate) {

        $currency = Currency::where('id', $quote)->first();

        if(!$currency) return 0;

        return math_formatter($currency->rate * $rate, get_p2p_decimals($currency->symbol));
    }

    public function getLowestOrderPrice($base, $quote, $rate) {

        $ad = PeerAd::query();

        $ad->select(DB::raw("*, (CASE WHEN price_type = 'fixed' THEN price ELSE ({$rate} * price_percentage) / 100 END) as offered_price"));

        $ad->where('base_currency_id', $base);
        $ad->where('quote_currency_id', $quote);
        $ad->where('type', 'sell');
        $ad->orderBy(DB::raw('offered_price'), 'ASC');

        $model = $ad->first();

        if(!$model) return 0;

        return $model->offered_price;
    }

    public function getHighestOrderPrice($base, $quote, $rate) {
        $ad = PeerAd::query();
        $ad->select(DB::raw("*, (CASE WHEN price_type = 'fixed' THEN price ELSE ({$rate} * price_percentage) / 100 END) as offered_price"));

        $ad->where('base_currency_id', $base);
        $ad->where('quote_currency_id', $quote);
        $ad->where('type', 'buy');
        $ad->orderBy(DB::raw('offered_price'), 'DESC');

        $model = $ad->first();

        if(!$model) return 0;

        return $model->offered_price;
    }

    public function hasActiveOrder($ad) {
        $order = PeerOrder::query();

        $order->where('ad_id', $ad);

        $order->where(function ($query) use ($ad) {
            $query->where('status', '!=', 'completed');
            $query->where('status', '!=', 'cancelled_by_counterparty');
            $query->where('status', '!=', 'cancelled_by_owner');
            $query->where('status', '!=', 'cancelled_by_system');
        });

        return $order->count();
    }

    public function isUserInBlacklist($user, $blockedUser) {

        return PeerUserBlacklist::where('user_id', $user)->where('blocked_user_id', $blockedUser)->exists();
    }
}
