<?php

namespace App\Events;

use App\Models\Market\Market;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderBookSnapshot implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $asks;
    public $bids;
    public $market;
    public $book_status;

    public $queue = 'orderbook';

    protected ?Market $marketModel = null;

    protected bool $marketResolved = false;

    /**
     * Create a new event instance.
     *
     * @param $order
     * @param String $type
     */
    public function __construct($market, $bids, $asks, ?Market $marketModel = null)
    {
        $this->market = $market;
        $this->marketModel = $marketModel;
        $model = $this->findMarketByName($market);
        $book = $model ? app(\App\Services\Market\FundedLiquidity::class)->publicBook($model) : ['bids'=>[], 'asks'=>[]];
        $this->bids = $book['bids']; $this->asks = $book['asks'];
        $this->book_status = $model ? \App\Services\Market\OrderBookStatus::metadata($model,$book) : ['state'=>'empty'];
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
            'bids' => $this->bids,
            'asks' => $this->asks,
            'book_status' => $this->book_status
        ];
    }

    protected function findMarketByName($marketName): ?Market
    {
        if ($this->marketResolved) {
            return $this->marketModel;
        }

        if ($this->marketModel instanceof Market) {
            $this->marketResolved = true;
            return $this->marketModel;
        }

        $market = Market::where('name', $marketName)->first();

        if (!$market && is_string($marketName)) {
            $market = Market::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(UPPER(name), '-', ''), '_', ''), '/', '') = ?", [
                    strtoupper(str_replace(['-', '_', '/', ' '], '', $marketName)),
                ])
                ->first();
        }

        $this->marketModel = $market;
        $this->marketResolved = true;

        return $this->marketModel;
    }

}
