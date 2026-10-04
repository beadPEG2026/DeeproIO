<?php

namespace App\Modules\P2P\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PeerMerchantKycDocument extends JsonResource
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
