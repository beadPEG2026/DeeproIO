<?php

namespace App\Modules\P2P\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PeerOrderAppeal extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        if($this->type == "system") {
            $sellername = '';
        } else {
            $sellername = $this->user->peer_username;
            $sellername = mask_nickname($sellername, $this->user->email);
        }


        return [
            'id' => $this->id,
            'content' => $this->content,
            'type' => $this->type,
            'status' => $this->status,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'user' => $this->user ? [
                'referral_code' => $this->user->referral_code,
                'name' => $sellername[0],
                'is_online' => $this->user->is_online
            ] : null,
            'attachments' => $this->attachments
        ];
    }
}
