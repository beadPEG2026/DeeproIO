<?php

namespace App\Http\Resources\Order;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class OpenFuturesOrder extends JsonResource
{
    public $custom_fields = [];

    public function __construct($resource, $custom_fields = [])
    {
        $this->custom_fields = $custom_fields;

        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        if($this->created_at instanceof Carbon) {
            $date = $this->created_at->format('Y-m-d H:i:s');
        } else {
            $date = $this->created_at;
        }

        $marketPrice = math_formatter(market_get_stats($this->market->id, 'last'), $this->market->quote_precision, false, true);

        if ($this->status === 'active') {
            $pnl = futures_pnl_calculate($this->quantity, $this->price, $marketPrice, $this->leverage, $this->is_long);
            $pnlAmount = math_formatter(math_percentage($this->balance, $pnl), $this->market->quote_precision, false, true);
        } else {
            $pnl = 0;
            $pnlAmount = 0;
        }

        $order = [
            'id' => $this->id,
            'is_long' => $this->is_long,
            'market' => $this->market->name,
            'baseSymbol' => $this->market->baseCurrency->symbol,
            'quoteSymbol' => $this->market->quoteCurrency->symbol,
            'created_at' => $date,
            'quantity' =>  math_formatter($this->quantity, $this->market->base_precision),
            'balance' => $this->balance,
            'released_amount' => $this->released_amount,
            'price' => math_formatter($this->price, $this->market->quote_precision, false, true),
            'market_price' => $marketPrice,
            'leverage' => intval($this->leverage),
            'liquidation_price' =>  math_formatter($this->liquidation_price, $this->market->quote_precision, false, true),
            'take_profit_price' => math_formatter($this->take_profit_price, $this->market->quote_precision, false, true),
            'stop_loss_price' => math_formatter($this->stop_loss_price, $this->market->quote_precision, false, true),
            'type' => $this->is_long ? 'long' : 'short',
            'order_type' => $this->type ?? 'market', // 'market' or 'limit'
            'pnl' => $pnl,
            'pnlAmount' => $pnlAmount,
            'pnl_profitable' => $pnl >= 0,
            'status' => $this->status,
            'scheduled_status' => $this->scheduled_status,
            'timeframe_seconds' => (int) ($this->timeframe_seconds ?? 0),
            'start_at' => $this->start_at ? $this->start_at->format('Y-m-d H:i:s') : null,
            'total_funding_fee_paid' => $this->total_funding_fee_paid ?? '0',
            'last_funding_fee_at' => $this->last_funding_fee_at ? $this->last_funding_fee_at->format('Y-m-d H:i:s') : null,
        ];

        if($this->custom_fields && is_array($this->custom_fields)) {
            $order = array_merge($order, $this->custom_fields);
        }

        return $order;
    }
}
