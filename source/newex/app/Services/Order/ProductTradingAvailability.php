<?php
namespace App\Services\Order;
use App\Models\Market\Market;
use Illuminate\Validation\ValidationException;
/** Product switches only; authentication/role policy stays with the existing controllers. */
final class ProductTradingAvailability {
    public function check(Market $market, string $product, string $side): void {
        $enabled=$product==='options'?$market->has_options:$market->has_futures;
        if(\Setting::get('trade.disable_trades',false) || !$market->status || !$market->trade_status || !$enabled || !in_array($side,['buy','sell'],true) || !($side==='buy'?$market->buy_order_status:$market->sell_order_status))throw ValidationException::withMessages(['market'=>__('Trading is unavailable for this product or side.')]);
    }
}
