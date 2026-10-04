<?php

namespace App\Events;

use App\Models\Wallet\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Broadcasting\InteractsWithSockets;
use App\Http\Resources\Wallet\Wallet as WalletResources;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $wallet;

    public $queue = 'events';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Wallet $wallet)
    {
        $this->wallet = new WalletResources((new WalletService())->getWallet($wallet->id));
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('user-' . $this->wallet->user_id);
    }

    public function broadcastWith()
    {
        return ['wallet' => $this->wallet];
    }
}
