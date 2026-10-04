<?php

namespace App\Http\Resources\Lending;

use Illuminate\Http\Resources\Json\JsonResource;

class LendingRepayment extends JsonResource
{
    public static $wrap = null;
    public $preserveKeys = true;

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
            'collateral' => $this->collateral->symbol,
            'amount' => math_formatter($this->amount, 8),
            'created_at' => $this->created_at->format('d-m-Y H:i'),
        ];
    }
}
