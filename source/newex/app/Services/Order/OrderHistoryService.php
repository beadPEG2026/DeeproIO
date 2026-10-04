<?php

namespace App\Services\Order;

use App\Models\Order\OrderHistory;

class OrderHistoryService {

    /**
     * Reflect status of order history by quantity
     *
     * @param string $uuid
     * @param decimal $quantity
     * @return void
     */
    public function reflectStatusByQuantity($uuid, $quantity) {
        OrderHistory::where('id', $uuid)->update(['status' => $quantity <= 0 ? OrderHistory::ORDER_FILLED : OrderHistory::ORDER_CANCELLED]);
    }

}
