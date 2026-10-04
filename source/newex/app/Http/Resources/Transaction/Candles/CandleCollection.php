<?php

namespace App\Http\Resources\Transaction\Candles;

use App\Http\Resources\ApiCollection;

class CandleCollection extends ApiCollection
{
    public static $wrap = null;

    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return parent::toArray($request);
    }

    public function with($request)
    {
        return [];
    }
}
