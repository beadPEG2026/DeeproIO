<?php

namespace App\Events;

use App\Http\Resources\Market\TransactionCollection;
use App\Models\Market\Market;
use App\Services\Market\MarketService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use App\Http\Resources\Market\Market as MarketResource;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketStatsLiteUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $market;

    public $queue = 'market';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($market)
    {
        $this->market = $market;
        if (is_array($this->market) && empty($this->market['updated_at'])) {
            $this->market['updated_at'] = now()->toISOString();
        }
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('market');
    }

    public function broadcastWith()
    {
        return ['market' => $this->market];
    }
}
