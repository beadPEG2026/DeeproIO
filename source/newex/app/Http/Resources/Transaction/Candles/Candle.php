<?php

namespace App\Http\Resources\Transaction\Candles;

use Illuminate\Http\Resources\Json\JsonResource;

class Candle extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            't2' => $this->date2,
            't' => $this->date,
            'h' => $this->high,
            'l' => $this->low,
            'o' => $this->open,
            'c' => $this->close,
            'v' => $this->volume,
        ];
    }
}
