<?php

namespace App\Http\Resources\Transaction;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class FuturesTransaction extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        if ($this->created_at instanceof Carbon) {
            $date = $this->created_at->format('Y-m-d H:i:s');
        } else {
            $date = $this->created_at;
        }

        if ($this->updated_at instanceof Carbon) {
            $updatedAt = $this->updated_at->format('Y-m-d H:i:s');
        } else {
            $updatedAt = $this->updated_at;
        }

        $order = [
            'id' => $this->id,
            'is_long' => $this->is_long,
            'market' => $this->market->name,
            'created_at' => $date,
            'updated_at' => $updatedAt,

            'quantity' => $this->formatDecimal($this->quantity, 4),
            'balance' => $this->formatDecimal($this->balance, 2),
            'released_amount' => $this->formatDecimal($this->released_amount, 2),
            'price' => $this->formatDecimal($this->price, 6),
            'leverage' => intval($this->leverage),

            'liquidation_price' => $this->formatDecimal($this->liquidation_price, 6),
            'take_profit_price' => $this->formatDecimal($this->take_profit_price, 6),
            'stop_loss_price' => $this->formatDecimal($this->stop_loss_price, 6),

            'entry_fee' => $this->formatDecimal($this->entry_fee, 6),
            'close_price' => $this->formatDecimal($this->close_price, 6),
            'exit_fee' => $this->formatDecimal($this->exit_fee, 6),
            'fee_rate' => $this->formatDecimal($this->fee_rate, 4),
            'total_funding_fee_paid' => $this->formatDecimal($this->total_funding_fee_paid, 6),

            'last_funding_fee_at' => $this->last_funding_fee_at
                ? ($this->last_funding_fee_at instanceof Carbon
                    ? $this->last_funding_fee_at->format('Y-m-d H:i:s')
                    : $this->last_funding_fee_at)
                : null,

            'type' => $this->is_long ? 'long' : 'short',
            'pnl' => $this->formatDecimal($this->pnl, 2),
            'pnl_profitable' => $this->pnl >= 0,
            'status' => $this->status,

            'timeframe_seconds' => (int) ($this->timeframe_seconds ?? 0),
            'start_at' => $this->start_at
                ? ($this->start_at instanceof Carbon
                    ? $this->start_at->format('Y-m-d H:i:s')
                    : $this->start_at)
                : null,
            'activated_at' => $this->activated_at
                ? ($this->activated_at instanceof Carbon
                    ? $this->activated_at->format('Y-m-d H:i:s')
                    : $this->activated_at)
                : null,

            'quote_symbol' => $this->market->quoteCurrency->symbol ?? null,
        ];

        if ($this->custom_fields && is_array($this->custom_fields)) {
            $order = array_merge($order, $this->custom_fields);
        }

        return $order;
    }

    protected function formatDecimal($value, $decimals = 2)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, $decimals, '.', '');
    }
}
