<?php

namespace App\Modules\P2P\Http\Resources;

use App\Http\Resources\ApiCollection;

class UserPaymentMethodCollection extends ApiCollection
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
