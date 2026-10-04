<?php

namespace App\Services\PaymentGateways\Coin\Coinpayments\Resources;

use App\Http\Resources\ApiCollection;

class CoinpaymentsCollection extends ApiCollection
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
