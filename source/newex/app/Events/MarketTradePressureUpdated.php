<?php

namespace App\Events;

use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Http\Resources\Transaction\Transaction as TransactionResource;

class MarketTradePressureUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public $buy;
    public $sell;
    public $market;

    public $queue = 'market';

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param String $type
     */
    public function __construct($market, $buy, $sell)
    {
        $this->buy = $buy;
        $this->sell = $sell;
        $this->market = $market;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('orderbook-'.$this->market);
    }

    public function broadcastWith()
    {
        return [
            'market' => $this->market,
            'buy' => $this->buy,
            'sell' => $this->sell,
        ];
    }
}
