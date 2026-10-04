<?php

namespace App\Http\Resources\KycDocument;

use Illuminate\Http\Resources\Json\JsonResource;

class KycDocument extends JsonResource
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
            'rejected_reason' => $this->rejected_reason,
        ];
    }
}
