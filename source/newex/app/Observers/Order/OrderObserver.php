<?php

namespace App\Observers\Order;

use App\Models\Order\Order;
use App\Services\Order\OrderHistoryService;

class OrderObserver
{
    public $orderHistoryService;

    /**
     * Listen to the Order created event.
     *
     * @param  \App\Models\Order\Order $order
     * @return void
     */
    public function created(Order $order)
    {

    }

    /**
     * Listen to the Order deleted event.
     *
     * @param  \App\Models\Order\Order $order
     * @return void
     */
    public function deleted(Order $order)
    {

    }

    /**
     * Listen to the Order updated event.
     *
     * @param  \App\Models\Order\Order $order
     * @return void
     */
    public function updated(Order $order)
    {

    }
}
