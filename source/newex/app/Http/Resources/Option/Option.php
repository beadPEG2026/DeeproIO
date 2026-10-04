<?php

namespace App\Http\Resources\Option;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class Option extends JsonResource
{
    public $custom_fields = [];

    public function __construct($resource, $custom_fields = [])
    {
        $this->custom_fields = $custom_fields;

        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $pnl = 0;

        $typesMap = config('app.options_types');

        // Resolve duration seconds: prefer explicit timeframe_seconds over period mapping
        $durationSeconds = $this->timeframe_seconds ?? ($typesMap[$this->period] ?? 0);

        // Resolve base (start) time: prefer start_at when available
        $baseTime = $this->start_at ? Carbon::parse($this->start_at) : Carbon::parse($this->created_at);

        $diffSeconds = 0;

        if($this->status == 'active') {
            $targetTime = $baseTime->getTimestamp() + (int) $durationSeconds;
            $diffSeconds =  $targetTime - time();
            if($diffSeconds < 0) {
                $diffSeconds = 0;
            }
        }

        if($this->status == "won") $pnl = $this->pnl;
        if($this->status == "lost") $pnl = $this->amount;

        $order = [
            'created_at' => Carbon::parse($this->created_at)->format('Y-m-d H:i'),
            'start_at' => $this->start_at ? Carbon::parse($this->start_at)->format('Y-m-d H:i:s') : null,
            'market' => $this->market->name,
            'symbol' => $this->currency->symbol,
            'amount' => $this->amount,
            'price' => $this->price,
            'market_price' => $this->market_price,
            'type' => $this->type,
            'period' => $this->period,
            'timeframe_seconds' => $durationSeconds,
            'remaining' => $diffSeconds,
            'period_text' => $durationSeconds,
            'status' => $this->status,
            'settlement_review_required' => $this->status === 'review_required',
            'settlement_source' => $this->settlement_source,
            'pnl' => $pnl,
            'id' => $this->uuid,
        ];

        return $order;
    }
}
