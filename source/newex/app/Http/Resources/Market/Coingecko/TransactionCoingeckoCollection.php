<?php

namespace App\Http\Resources\Market\Coingecko;

use App\Http\Resources\ApiCollection;

class TransactionCoingeckoCollection extends ApiCollection
{
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
}
