<?php

namespace App\Modules\P2P\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PeerOrderMessage extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $user = $request->user();
        $zoom_path = '';

        $message = $this->message;

        if($this->type == "media") {
            $message = url($this->message);
        } elseif($this->type == "image") {

            $pos = mb_strrpos($this->message, '.');
            $prefix = mb_substr($this->message, 0, $pos);
            $suffix = mb_substr($this->message, $pos + 1);

            $message = url($prefix . '_cropped.' . $suffix);
            $zoom_path = $this->message;
        }

        return [
            'id' => md5($this->created_at . $this->id),
            'content' => $message,
            'zoom_content' => $zoom_path,
            'type' => $this->type,
            'is_system' => $this->is_system,
            'is_author' => $user->id == $this->user_id,
            'seen' => $this->seen,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
