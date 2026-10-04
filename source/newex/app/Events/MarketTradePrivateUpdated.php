<?php

namespace App\Events;

use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Http\Resources\Transaction\Transaction as TransactionResource;

class MarketTradePrivateUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $market;
    public $transaction;
    public $silent = '';

    public $queue = 'market';

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param String $type
     */
    public function __construct($transaction, $silent = true)
    {
        $this->transaction = $transaction;
        $this->market = $transaction->market;
        $this->silent = $silent;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('user-' . $this->transaction->user_id);
    }

    public function broadcastWith()
    {
        $transaction = new TransactionResource($this->transaction);

        $splitMarket = explode('-', $this->market->name);

        if(count($splitMarket) < 2) {
            $splitMarket = explode('_', $this->market->name);
        }

        return [
            'market' => [
                'name' => $this->market->name,
                'base' => $splitMarket[0] ?? '',
                'basePrecision' => $this->market->base_precision,
                'quote' => $splitMarket[1] ?? '',
                'quotePrecision' => $this->market->quote_precision,
            ],
            'trade' => $transaction,
            's' => $this->silent,
        ];
    }
}
