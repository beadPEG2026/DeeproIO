<?php

namespace App\Http\Resources\Lending;

use App\Http\Resources\ApiCollection;

class LendingRepaymentCollection extends ApiCollection
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
