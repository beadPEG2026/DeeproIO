<?php

namespace App\Http\Resources\Currency;

use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyLogo extends JsonResource
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
            'id' => $this->id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'logo' => $this->resource->logo_path,
        ];
    }
}
