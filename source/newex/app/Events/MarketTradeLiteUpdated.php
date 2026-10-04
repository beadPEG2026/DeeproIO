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
use Illuminate\Support\Facades\Cache;

class MarketTradeLiteUpdated implements ShouldBroadcastNow
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
        return new Channel('orderbook-'.$this->market->name);
    }

    public function broadcastWhen()
    {
        return !$this->isKlineAdjustmentTradeSuppressed();
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

    protected function isKlineAdjustmentTradeSuppressed(): bool
    {
        $marketId = (int)($this->market->id ?? 0);

        if ($marketId <= 0) {
            return false;
        }

        if (!empty($this->transaction->kline_adjustment_tick)) {
            return false;
        }

        if (!$this->isKlineAdjustmentRuntimeActive($marketId)) {
            return false;
        }

        $adjustedAt = Cache::get('market_kline_adjusted_at_' . $marketId);

        if (!$adjustedAt) {
            return false;
        }

        $timestamp = strtotime((string)$adjustedAt);

        return $timestamp > 0 && (time() - $timestamp) <= 30;
    }

    protected function isKlineAdjustmentRuntimeActive(int $marketId): bool
    {
        $runtimeConfig = Cache::get('market_kline_runtime_config_' . $marketId, []);

        if (!is_array($runtimeConfig)) {
            $runtimeConfig = [];
        }

        if (array_key_exists('custom_liquidity_t', $runtimeConfig)) {
            $customActive = filter_var($runtimeConfig['custom_liquidity_t'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $customActive = (bool)($this->market->custom_liquidity_t ?? false);
        }

        if (!$customActive) {
            return false;
        }

        $percent = array_key_exists('bot_price_ceiling', $runtimeConfig)
            ? (float) $runtimeConfig['bot_price_ceiling']
            : (float)($this->market->bot_price_ceiling ?? 0);

        return abs($percent) > 0.00000001;
    }
}
