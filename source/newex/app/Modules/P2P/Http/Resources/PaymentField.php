<?php

namespace App\Modules\P2P\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentField extends JsonResource
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
            'name' => $this->title,
            'required' => $this->required
        ];
    }
}
