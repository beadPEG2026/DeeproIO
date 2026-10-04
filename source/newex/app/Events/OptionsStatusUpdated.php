<?php

namespace App\Events;

use App\Models\Option\Option;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OptionsStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $option, $symbol;

    public $queue = 'events';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Option $option, $symbol)
    {
        $this->option = $option;
        $this->symbol = $symbol;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('user-' . $this->option->user_id);
    }

    public function broadcastWith()
    {
        $pnl = $this->option->pnl;

        if($this->option->status == "lost") {
            $pnl = $this->option->amount;
        }

        return ['pnl' => $pnl, 'symbol' => $this->symbol, 'status' => $this->option->status];
    }
}
