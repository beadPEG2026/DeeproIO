<?php

namespace App\Events;

use App\Models\Order\Order;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $market;
    public $type;
    public $user;
    public $quantity;

    public $queue = 'events';

    /**
     * Create a new event instance.
     *
     * @param $order
     * @param String $type
     */
    public function __construct($order, $quantity = 0)
    {
        $this->order = $order;
        $this->market = $order['market'];
        $this->user = $order['user'];
        $this->quantity = $quantity;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('user-' . $this->user);
    }

    public function broadcastWith()
    {
        $payload = [
            'id' => $this->order['id'],
            'side' => $this->order['side'],
            'price' => $this->order['price'],
            'quantity' => $this->order['quantity'],
            'created_at' => $this->order['created_at'],
            'market' => $this->market,
        ];

        return [
            'order' => $payload,
        ];
    }
}
