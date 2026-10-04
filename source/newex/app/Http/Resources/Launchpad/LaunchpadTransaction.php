<?php

namespace App\Http\Resources\Launchpad;

use Illuminate\Http\Resources\Json\JsonResource;

class LaunchpadTransaction extends JsonResource
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
            'amount' => $this->amount,
            'name' => $this->launchpad->name,
            'is_credited' => $this->is_credited,
            'currency' => $this->launchpad->network_id == 5 ? 'BNB' : 'ETH',
            'user' => $this->user->email,
            'created_at' => $this->created_at->format('d-m-Y H:i:s'),
        ];
    }
}
