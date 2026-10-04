<?php

namespace App\Modules\P2P\Repositories\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerFeedback;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderMessage;
use Auth;

class PeerOrderChatRepository
{
    /**
     * @var PeerOrder
     */
    protected $model;

    public function storeSystemMessage($order, $message, $side) {

        $this->model = new PeerOrderMessage();

        if($side == "owner") {

            $this->model->id = generate_uuid();
            $this->model->order_id = $order->id;
            $this->model->is_system = true;
            $this->model->message = $message;
            $this->model->is_visible_owner = true;
            $this->model->order_owner_id = $order->ad_user_id;
            $this->model->order_counterparty_id = $order->user_id;
            $this->model->save();

        } elseif($side == "counterparty") {

            $this->model->id = generate_uuid();
            $this->model->order_id = $order->id;
            $this->model->is_system = true;
            $this->model->message = $message;
            $this->model->is_visible_counterparty = true;
            $this->model->order_owner_id = $order->ad_user_id;
            $this->model->order_counterparty_id = $order->user_id;
            $this->model->save();

        } elseif($side == "both") {

            $this->model->id = generate_uuid();
            $this->model->order_id = $order->id;
            $this->model->is_system = true;
            $this->model->message = $message;
            $this->model->is_visible_counterparty = true;
            $this->model->is_visible_owner = true;
            $this->model->order_owner_id = $order->ad_user_id;
            $this->model->order_counterparty_id = $order->user_id;
            $this->model->save();

        }

        return $this->model;
    }
}
