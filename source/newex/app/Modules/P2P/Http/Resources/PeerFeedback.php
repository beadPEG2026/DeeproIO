<?php

namespace App\Modules\P2P\Http\Resources;

use App\Http\Resources\User\User;
use Illuminate\Http\Resources\Json\JsonResource;

class PeerFeedback extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $user = new User($this->author);

        if($this->is_anonymous) {
            $user = false;
        }

        return [
            'id' => $this->id,
            'content' => $this->content,
            'reply_content' => $this->reply_content,
            'is_negative' => $this->is_negative,
            'is_anonymous' => $this->is_anonymous,
            'created_at' => $this->created_at->format('Y-m-d'),
            'author' => $user,
            'paymentMethod' => $this->paymentMethod ? $this->paymentMethod->title : null,
        ];
    }
}
