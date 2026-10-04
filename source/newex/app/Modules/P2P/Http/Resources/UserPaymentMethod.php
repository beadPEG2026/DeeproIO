<?php

namespace App\Modules\P2P\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserPaymentMethod extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $content = json_decode($this->content);

        foreach ($content as $field) {
            $fields[] = ['id' => $field->field_id, 'field' => DB::table('peer_payment_fields')->select('title')->where('id', $field->field_id)->value('title'), 'content'=> $field->content];
        }

        return [
            'id' => $this->id,
            'name' => $this->paymentMethod->title,
            'color' => $this->paymentMethod->color,
            'method_id' => $this->paymentMethod->id,
            'fields' => $fields,
        ];
    }
}
