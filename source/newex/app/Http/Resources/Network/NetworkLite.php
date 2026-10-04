<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Http\Resources\Network;

use Illuminate\Http\Resources\Json\JsonResource;

class NetworkLite extends JsonResource
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
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
