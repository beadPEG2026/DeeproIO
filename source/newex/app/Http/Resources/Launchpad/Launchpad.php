<?php

namespace App\Http\Resources\Launchpad;

use Illuminate\Http\Resources\Json\JsonResource;

class Launchpad extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'network' => $this->network_id == 5 ? 'BEP-20' : 'ERC-20',
            'network_symbol' => $this->network_id == 5 ? 'BNB' : 'ETH',
            'description' => \App\Services\Content\SafeHtml::render($this->description),
            'currency' => $this->currency->name,
            'currency_symbol' => $this->currency->symbol,
            'currency_logo' => url($this->currency->logo_path),
            'soft_cap' => $this->soft_cap,
            'hard_cap' => $this->hard_cap,
            'rate' => $this->rate,
            'min_buy' => $this->min_buy,
            'max_buy' => $this->max_buy,
            'raised_amount' => $this->raised_amount,
            'filled' => math_formatter(math_percentage_progress($this->raised_amount, $this->hard_cap), 2, true),
            'status' => $this->progress,
            'start_time' => $this->start_time->format('d-m-Y H:i'),
            'end_time' => $this->end_time->format('d-m-Y H:i'),
            'dy_am' => $this->dy_am,
            'kt_sl' => $this->kt_sl,
        ];
    }
}
