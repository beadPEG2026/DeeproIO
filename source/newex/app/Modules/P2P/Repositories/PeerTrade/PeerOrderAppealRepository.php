<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Modules\P2P\Mail\Orders\AppealReceived;
use App\Modules\P2P\Mail\Orders\AppealUpdated;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderAppeal;
use Auth;
use Illuminate\Support\Facades\Mail;

class PeerOrderAppealRepository
{
    public function storeAppeal($data) {

        $user = auth()->user();

        $order = PeerOrder::find($data['id']);

        $status = $order->status;

        $counterPartyUser = $order->user;

        if($order->user->id == $user->id) {
            $counterPartyUser = $order->seller;
        }

        $order->previous_status = $status;
        $order->status = "appealed_by_counterparty";
        $order->appeal_stage = "pending";
        $order->appealed_at = now();
        $order->appealed_by = $user->id;
        $order->appealed_user = $counterPartyUser->id;
        $order->update();

        $model = new PeerOrderAppeal();
        $model->id = generate_uuid();
        $model->order_id = $data['id'];
        $model->content = $data['reason'];
        $model->type = 'message';
        $model->appeal_author_id = $user->id;
        $model->appealed_user = $counterPartyUser->id;
        $model->status = 'pending';
        $model->is_order_owner = true;
        $model->save();

        $model->attachments()->sync($data['attachments']);

        $mailData = [
            'order_id' => $order->id,
            'created_at' => $order->created_at,
            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
            'crypto_amount' => $order->amount . ' ' . $order->baseCurrency->symbol,
            'timeframe' => $order->timeframe,
        ];

        Mail::to($counterPartyUser->email)->queue(new AppealReceived($counterPartyUser, $mailData));

        return $model;
    }

    public function respondAppeal($data) {

        $user = auth()->user();

        $status = "responded";

        $order = PeerOrder::find($data['id']);

        if($user->id == $order->appealed_by) {
            $status = "pending";
        }

        $order->appeal_stage = $status;
        $order->update();

        $counterParty = $order->user_id == $user->id ? $user : $order->seller;

        $model = new PeerOrderAppeal();
        $model->id = generate_uuid();
        $model->order_id = $data['id'];
        $model->content = $data['reason'];
        $model->type = 'message';
        $model->appeal_author_id = $user->id;
        $model->appealed_user = $order->user_id == $user->id ? $user->id : $order->ad_user_id;
        $model->status = 'pending';
        $model->is_order_owner = true;
        $model->save();

        $model->attachments()->sync($data['attachments']);

        $mailData = [
            'result' => __('The counterparty responded to this appeal:'),
            'result2' => $data['reason'],
            'order_id' => $order->id,
            'created_at' => $order->created_at,
            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
            'crypto_amount' => $order->amount . ' ' . $order->baseCurrency->symbol,
            'timeframe' => $order->timeframe,
        ];

        Mail::to($counterParty->email)->queue(new AppealUpdated($counterParty, $mailData));

        return $model;
    }

    public function submitAppealReview($data, $stage = 'pending') {

        $turn = $data['turn'] ?? 'defendant';

        $order = PeerOrder::find($data['id']);
        $order->appeal_stage = $turn == "defendant" ? $stage : "responded";
        $order->update();

        $appealed_user = $order->user_id;
        $isOrderOwner = $order->appealed_by == $order->user_id;

        if($order->appealed_by == $appealed_user) {
            $appealed_user = $order->ad_user_id;
        }

        $model = new PeerOrderAppeal();
        $model->id = generate_uuid();
        $model->order_id = $data['id'];
        $model->content = $data['message'];
        $model->type = 'review';
        $model->appeal_author_id = $order->appealed_by;
        $model->appealed_user = $appealed_user;
        $model->status = 'pending';
        $model->is_order_owner = $isOrderOwner;
        $model->save();

        $mailData = [
            'result' => __('The Customer Support responded to this appeal:'),
            'result2' => $data['message'],
            'order_id' => $order->id,
            'created_at' => $order->created_at,
            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
            'crypto_amount' => $order->amount . ' ' . $order->baseCurrency->symbol,
            'timeframe' => $order->timeframe,
        ];

        Mail::to($order->user->email)->queue(new AppealUpdated($order->user, $mailData));
        Mail::to($order->seller->email)->queue(new AppealUpdated($order->seller, $mailData));

        return $model;
    }

    public function getAppealsById($id, $user = false) {

        $appeal = PeerOrderAppeal::query();

        $appeal->where('order_id', $id);

        $appeal->with('attachments');

        $appeal->orderBy('created_at', 'desc');

        return $appeal->get();
    }

    public function cancelAppeal($data) {

        $order = PeerOrder::find($data['id']);
        $order->status = $order->previous_status;
        $order->appeal_stage = "cancelled_by_owner";
        $order->update();

        if($order->user_id == $order->appealed_user) {
            $appealedUser = $order->user;
        } else {
            $appealedUser = $order->seller;
        }

        $model = new PeerOrderAppeal();
        $model->id = generate_uuid();
        $model->order_id = $data['id'];
        $model->type = 'system';
        $model->status = 'cancelled_by_owner';
        $model->is_order_owner = true;
        $model->save();

        $mailData = [
            'result' => __('Appeal was cancelled by counterparty.'),
            'order_id' => $order->id,
            'created_at' => $order->created_at,
            'fiat_amount' => $order->quote_amount . ' ' . $order->quoteCurrency->symbol,
            'crypto_amount' => $order->amount . ' ' . $order->baseCurrency->symbol,
            'timeframe' => $order->timeframe,
        ];

        Mail::to($appealedUser->email)->queue(new AppealUpdated($appealedUser, $mailData));

    }
}
