<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Modules\P2P\Mail\Orders\AppealUpdated;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderAppeal;
use Auth;
use Illuminate\Support\Facades\Mail;

class PeerOrdersAppealsRepository
{
    /**
     * @var PeerOrderAppeal
     */
    protected $appeal;

    /**
     * PeerPaymentMethodRepository constructor.
     *
     */
    public function __construct()
    {
        $this->appeal = new PeerOrderAppeal();
    }

    public function getAppealById($id, $dashboard = false, $relations = null) {

        $appeal = PeerOrderAppeal::whereId($id);

        if($relations !== null) {
            $appeal->with($relations);
        }

        if(!$dashboard) {
            $appeal->active();
        }

        return $appeal->first();
    }

    public function all($paginate, $dashboard = false, $relations = []) {

        $appeals = PeerOrderAppeal::filter(request()->only(['search']))->orderByLatest();

        if(!$dashboard) {
            $appeals->active();
        }

        $appeals->with($relations);

        if($paginate) {
            return $appeals->paginate(24)->withQueryString();
        } else {
            return $appeals->get();
        }
    }

    public function list($paginate, $dashboard = false, $relations = []) {

        $appeals = PeerOrder::query();

        $appeals->with($relations);

        $appeals->whereNotNull('appeal_stage');

        if($paginate) {
            return $appeals->paginate(24)->withQueryString();
        } else {
            return $appeals->get();
        }
    }

    public function count() {
        $appeals = PeerOrderAppeal::query();
        return $appeals->count();
    }

    public function moderate($id, $status) {

        $peerOrderRepository = new PeerOrderRepository();
        $peerOrderAppeal = new PeerOrderAppealRepository();

        $order = PeerOrder::where('id', $id)->first();

        $data = [
            'id' => $id,
        ];

        if(!$order->appeal_stage || $order->appeal_stage == "moderated") {
            return false;
        }

        if($status == "approve_release") {

            $peerOrderRepository->release($order);

            $data['message'] = __('The appeal has been approved and order funds were released.');
            $peerOrderAppeal->submitAppealReview($data, 'moderated');

        } elseif($status == "approve_cancel") {
            $peerOrderRepository->cancel($order, true);

            $data['message'] = __('The appeal has been approved and order was cancelled.');
            $peerOrderAppeal->submitAppealReview($data, 'moderated');

        } elseif($status == "reject_release") {
            $peerOrderRepository->release($order);

            $data['message'] = __('The appeal has been rejected and order funds were released.');
            $peerOrderAppeal->submitAppealReview($data, 'moderated');

        } elseif($status == "reject_cancel") {
            $peerOrderRepository->cancel($order, true);

            $data['message'] = __('The appeal has been rejected and order was cancelled.');
            $peerOrderAppeal->submitAppealReview($data, 'moderated');

        }

        $mailData = [
            'result' => __('The Customer Support moderated this appeal with the following result:'),
            'result2' => $data['message'],
            'order_id' => $order->id,
            'created_at' => $order->created_at,
            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
            'crypto_amount' => $order->amount . ' ' . $order->baseCurrency->symbol,
            'timeframe' => $order->timeframe,
        ];

        Mail::to($order->user->email)->queue(new AppealUpdated($order->user, $mailData));
        Mail::to($order->seller->email)->queue(new AppealUpdated($order->seller, $mailData));

        return $order;
    }
}
