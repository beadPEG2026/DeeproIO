<?php

namespace App\Events;

use App\Models\Withdrawal\Withdrawal;
use App\Repositories\Withdrawal\WithdrawalRepository;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Http\Resources\Wallet\Withdrawal\Withdrawal as WithdrawalResource;

class WithdrawalUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $withdrawal;

    public $queue = 'events';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawal = new WithdrawalResource((new WithdrawalRepository())->getWithdrawal($withdrawal->id));
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('user-' . $this->withdrawal->user_id);
    }

    public function broadcastWith()
    {
        return [
            'withdrawal' => $this->withdrawal
        ];
    }
}
