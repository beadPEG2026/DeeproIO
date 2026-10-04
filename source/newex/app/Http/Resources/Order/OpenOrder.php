<?php

namespace App\Http\Resources\Order;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class OpenOrder extends JsonResource
{
    public $custom_fields = [];

    public function __construct($resource, $custom_fields = [])
    {
        $this->custom_fields = $custom_fields;

        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        if($this->created_at instanceof Carbon) {
            $date = $this->created_at->format('Y-m-d H:i:s');
        } else {
            $date = $this->created_at;
        }

        $order = [
            'market' => $this->market->name,
            'created_at' => $date,
            'quantity' => $this->quantity,
            'price' => $this->price,
        ];

        if($this->id) {
            $order['id'] = $this->id;
            $order['side'] = $this->side;
            $order['type'] = $this->type;
        }

        if($this->custom_fields && is_array($this->custom_fields)) {
            $order = array_merge($order, $this->custom_fields);
        }

        return $order;

    }
}
