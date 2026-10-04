<?php

namespace App\Http\Resources\Market\Cmc;

use App\Http\Resources\ApiCollection;

class TransactionCmcCollection extends ApiCollection
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
