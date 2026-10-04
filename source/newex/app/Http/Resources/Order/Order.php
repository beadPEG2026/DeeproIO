<?php

namespace App\Http\Resources\Order;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class Order extends JsonResource
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
            'created_at' => $date,
            'quantity' => math_formatter($this->quantity, $this->market->base_precision, false, true),
            'price' => math_formatter($this->price, $this->market->quote_precision, false, true),
            'market' => $this->market->name,
            'baseSymbol' => $this->market->baseCurrency->symbol,
            'quoteSymbol' => $this->market->quoteCurrency->symbol,
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
