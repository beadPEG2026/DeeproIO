<?php

namespace App\Events;

use App\Models\Deposit\Deposit;
use App\Repositories\Deposit\DepositRepository;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Http\Resources\Wallet\Deposit\Deposit as DepositResource;

class DepositUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $deposit, $type;

    public $queue = 'events';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Deposit $deposit, $type)
    {
        $this->type = $type;
        $this->deposit = new DepositResource((new DepositRepository())->getDeposit($deposit->id));
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('user-' . $this->deposit->user_id);
    }

    public function broadcastWith()
    {
        return [
            'type' => $this->type,
            'deposit' => $this->deposit
        ];
    }
}
