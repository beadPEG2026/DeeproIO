<?php

namespace App\Events;

use App\Models\Order\Order;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class OrderBookRefreshed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $queue = 'events';

    public $market;

    /**
     * Create a new event instance.
     *
     */
    public function __construct($market)
    {
        $this->market = $market;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('orderbook-' . $this->market);
    }

    public function broadcastWith()
    {
        return [
            'time' => time()
        ];
    }
}
