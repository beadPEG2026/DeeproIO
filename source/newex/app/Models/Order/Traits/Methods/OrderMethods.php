<?php

namespace App\Models\Order\Traits\Methods;

use App\Models\Order\Order;
use App\Models\Order\OrderHistory;
use App\Models\Transaction\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait OrderMethods
{
    public function decrementField($field, $quantity)
    {
        DB::table('orders')
            ->where('id', $this->id)
            ->update([
                $field => DB::raw("$field - $quantity"),
            ]);
    }

    public function removeFromQueue($action)
    {
        // If order was cancelled and has transactions mark it as partially filled
        if($action == ORDER_STATUS_CANCELLED && Transaction::where('order_id', $this->id)->count()) {
            $action = ORDER_STATUS_PARTIALLY_FILLED;
        }

        OrderHistory::whereId($this->id)->update(['status' => $action]);
        $this->delete();
    }
}
